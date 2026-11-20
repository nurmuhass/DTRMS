<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DateTimeZone;
use Dtrms\Models\ReportAnalytics;
use Dtrms\Models\StaffReports;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\WorkHolidays;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;

final class ReportingSummary
{
    public function __construct(
        private ReportAnalytics $records,
        private array $policy,
        private StaffWorkSchedules $schedules,
        private StaffLeaves $leaves,
        private WorkHolidays $holidays
    )
    {
    }

    public function build(string $scope, int $actor, ReportFilters $filters, DateTimeImmutable $now): array
    {
        $records = $this->records->rows($scope, $actor, $filters);
        $roster = $this->records->roster($scope, $actor, $filters);
        [$days, $months, $expectedDays] = $this->calendar($filters, $now);
        $flexStart = $this->records->weeklySchedules()->start() ?: '9999-12-31';
        $flexMonths = $filters->until < $flexStart ? [] : $this->records->monitoring($this->policy['report_deadline'])->flexibleMonths(
            array_column($roster,'staff_id'),new DateTimeImmutable(max($filters->from,$flexStart),$now->getTimezone()),
            new DateTimeImmutable($filters->until,$now->getTimezone()),$now);
        $calendar = new WorkScheduleCalendar($this->schedules->calendar());
        $periods = $this->schedules->periods(array_column($roster, 'staff_id'), $filters->from, $filters->until);
        $events = $this->records->accountHistory(array_column($roster, 'staff_id'));
        $exemptions = $this->leaves->approvedSpans(array_column($roster, 'staff_id'), $filters->from, $filters->until);
        $holidays = $this->holidays->dates($filters->from, $filters->until);
        $stats = ['submitted' => 0, 'pending_supervisor' => 0, 'pending_hr' => 0, 'approved' => 0,
            'returned' => 0, 'recorded' => 0, 'minutes' => 0, 'assessed' => 0, 'late' => 0];
        $staff = [];
        $work = [];
        $departments = [];
        $attendance = [];
        $filtered = [];
        $optionRows = [];
        $expectedByJoinDate = [];
        $deadlines = [];
        foreach (array_keys($days) as $date) {
            $deadlines[$date] = (new DateTimeImmutable($date . ' ' . $this->policy['report_deadline'], $now->getTimezone()))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }
        $threshold = (int) substr($this->policy['work_start'], 0, 2) * 60
            + (int) substr($this->policy['work_start'], 3, 2) + $this->policy['arrival_grace'];

        foreach ($roster as $person) {
            $id = (int) $person['staff_id'];
            $joined = report_local_date($person['created_at']);
            $accountEvents = $events[$id] ?? [];
            $active = (bool) $person['is_active'];
            $work[$id] = ['joined' => $joined, 'department' => (int) $person['department_id'],
                'events' => $accountEvents, 'active' => $active];
            foreach ($months as $month => $lastDay) {
                if ($joined > $lastDay) {
                    continue;
                }
                $firstEligible = max($joined, $filters->from, $month . '-01');
                if (!$active || $accountEvents !== []) {
                    $activeThisMonth = false;
                    for ($day = new DateTimeImmutable($firstEligible); $day->format('Y-m-d') <= $lastDay; $day = $day->modify('+1 day')) {
                        if (self::activeAt($active, $accountEvents, $deadlines[$day->format('Y-m-d')])) {
                            $activeThisMonth = true;
                            break;
                        }
                    }
                    if (!$activeThisMonth) {
                        continue;
                    }
                }
                $staff[$id] = $person + ['submitted' => 0, 'pending' => 0, 'approved' => 0];
                $key = $month . ':' . $person['department_id'];
                $departments[$key] ??= self::department($month, $person['department_name']);
                $departments[$key]['staff']++;
                $flex = $flexMonths[$id][$month] ?? null;
                if ($flex) {
                    $departments[$key]['expected'] += (int) $flex['expected'];
                    $departments[$key]['fulfilled'] += (int) $flex['fulfilled'];
                    $departments[$key]['not_yet_due'] += (int)$flex['expected']-(int)$flex['fulfilled']-(int)$flex['due_outstanding'];
                }
                $eligiblePeriods = $periods[$id] ?? [];
                $leaveSpans = $exemptions[$id] ?? [];
                $departmentHolidays = $holidays[$person['department_id']] ?? [];
                $useDefaultCache = $accountEvents === [] && $active && $eligiblePeriods === []
                    && $leaveSpans === [] && $departmentHolidays === [];
                if ($useDefaultCache && isset($expectedByJoinDate[$firstEligible])) {
                    $departments[$key]['expected'] += $expectedByJoinDate[$firstEligible];
                    continue;
                }
                $expected = 0;
                foreach ($expectedDays[$month] ?? [] as $date => $weekday) {
                    $expected += (int) ($date < $flexStart && $date >= $firstEligible && $calendar->expects($date, $weekday, $eligiblePeriods)
                        && self::activeAt($active, $accountEvents, $deadlines[$date])
                        && !isset($holidays[0][$date]) && !isset($departmentHolidays[$date])
                        && !StaffLeaves::covers($date, $leaveSpans));
                }
                if ($useDefaultCache) {
                    $expectedByJoinDate[$firstEligible] = $expected;
                }
                $departments[$key]['expected'] += $expected;
            }
        }

        foreach ($records as $row) {
            $status = $row['status'];
            $date = $row['report_date'];
            $month = substr($date, 0, 7);
            $id = (int) $row['staff_id'];
            $submitted = SubmissionMonitoring::isSubmitted($status);
            $pending = $status === 'submitted' || in_array($status, ['supervisor_approved', 'pending_hr'], true);
            $returned = in_array($status, DailyReporting::RETURNED, true);
            $approved = $status === 'hr_approved';
            $recorded = $row['recorded_minutes'] !== null;
            $arrival = $row['recorded_arrival'];
            $night = in_array($row['recorded_overnight'], [1, '1', 'true', true], true);
            $assessed = $submitted && !$night && is_string($arrival)
                && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $arrival);
            $late = $assessed && ((int) substr($arrival, 0, 2) * 60 + (int) substr($arrival, 3, 2)) > $threshold;

            if ($submitted) {
                $key = $month . ':' . $row['department_id'];
                $departments[$key] ??= self::department($month, $row['department_name']);
                $department = &$departments[$key];
                $department['actual']++;
                $department['pending'] += (int) $pending;
                $department['supervisor'] += (int) $row['supervised'];
                $department['approved'] += (int) $approved;
                $department['returned'] += (int) $returned;
                $department['attendance'] += (int) $recorded;
                $department['minutes'] += (int) $row['recorded_minutes'];
                $department['late'] += (int) $late;
                if ($date < $flexStart && isset($expectedDays[$month][$date], $work[$id]) && $date >= $work[$id]['joined']
                    && (int) $row['department_id'] === $work[$id]['department']
                    && self::activeAt($work[$id]['active'], $work[$id]['events'], $deadlines[$date])
                    && $calendar->expects($date, $expectedDays[$month][$date], $periods[$id] ?? [])
                    && !isset($holidays[0][$date]) && !isset($holidays[$row['department_id']][$date])
                    && !StaffLeaves::covers($date, $exemptions[$id] ?? [])) {
                    $department['fulfilled']++;
                }
                unset($department);
            }

            if (!$filters->matchesStatus($status)) {
                continue;
            }
            $filtered[] = $row;
            $optionRows[$id] ??= ['staff_name' => $row['staff_name'], 'staff_code' => $row['staff_code'],
                'department_name' => $row['department_name'], 'submitted' => 0, 'pending' => 0, 'approved' => 0];
            if (!$submitted) {
                continue;
            }
            $days[$date]++;
            $stats['submitted']++;
            $stats['pending_supervisor'] += (int) ($status === 'submitted');
            $stats['pending_hr'] += (int) in_array($status, ['supervisor_approved', 'pending_hr'], true);
            $stats['approved'] += (int) $approved;
            $stats['returned'] += (int) $returned;
            $stats['recorded'] += (int) $recorded;
            $stats['minutes'] += (int) $row['recorded_minutes'];
            $stats['assessed'] += (int) $assessed;
            $stats['late'] += (int) $late;
            $optionRows[$id]['submitted']++;
            $optionRows[$id]['pending'] += (int) $pending;
            $optionRows[$id]['approved'] += (int) $approved;
            if ($recorded || $assessed) {
                $attendance[] = [report_reference($row), $date, $row['staff_name'], $row['staff_code'], $row['department_name'],
                    $assessed || $night ? report_clock($arrival) : null, $recorded ? (int) $row['recorded_minutes'] : null,
                    $assessed ? ($late ? 'Late' : 'On time') : ($night ? 'Overnight' : 'Unassessed')];
            }
        }

        foreach ($departments as &$department) {
            $department['missing'] = $department['expected'] - $department['fulfilled'];
            $department['due_outstanding'] = $department['missing'] - $department['not_yet_due'];
            $department['completion'] = $department['expected'] > 0 ? round(100 * $department['fulfilled'] / $department['expected'], 1) : null;
        }
        unset($department);
        $departments = array_values($departments);
        usort($departments, static fn (array $a, array $b): int => [$a['month'], $a['name']] <=> [$b['month'], $b['name']]);
        $monthly = array_fill_keys(array_keys($months), 0);
        foreach ($days as $day => $count) {
            $monthly[substr($day, 0, 7)] += $count;
        }
        if ($filters->status === '') {
            $optionRows += $staff;
        }
        $people = array_values($optionRows);
        usort($people, static fn (array $a, array $b): int => [-$a['submitted'], $a['staff_name']] <=> [-$b['submitted'], $b['staff_name']]);
        $approvals = $this->records->approvalGroups(array_column($filtered, 'id'));

        return compact('stats', 'departments', 'days', 'monthly', 'people', 'approvals', 'attendance', 'filtered', 'threshold');
    }

    private static function activeAt(bool $current, array $events, string $cutoff): bool
    {
        $active = $events === [] ? $current : !(bool) $events[0]['is_active'];
        foreach ($events as $event) {
            if ($event['changed_at'] > $cutoff) {
                break;
            }
            $active = (bool) $event['is_active'];
        }
        return $active;
    }

    private function calendar(ReportFilters $filters, DateTimeImmutable $now): array
    {
        $cutoff = ($now->format('H:i') < $this->policy['report_deadline'] ? $now->modify('-1 day') : $now)->format('Y-m-d');
        $days = $months = $expected = [];
        for ($day = new DateTimeImmutable($filters->from); ($date = $day->format('Y-m-d')) <= $filters->until; $day = $day->modify('+1 day')) {
            $month = substr($date, 0, 7);
            $days[$date] = 0;
            $months[$month] = $date;
            if ($date <= $cutoff) {
                $expected[$month][$date] = (int) $day->format('N');
            }
            if ($date === $filters->until) {
                break;
            }
        }
        return [$days, $months, $expected];
    }

    private static function department(string $month, string $name): array
    {
        return ['month' => $month, 'name' => $name, 'staff' => 0, 'expected' => 0, 'actual' => 0, 'fulfilled' => 0,
            'pending' => 0, 'supervisor' => 0, 'approved' => 0, 'returned' => 0, 'attendance' => 0, 'minutes' => 0, 'late' => 0,
            'not_yet_due'=>0];
    }
}
