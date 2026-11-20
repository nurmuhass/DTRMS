<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\HrReviews;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Models\UserAccount;
use Dtrms\Models\WeeklySchedules;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\MonitoringFilters;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\ReportingAccess;
use Dtrms\Services\StaffPeriodExports;
use Dtrms\Services\WorkScheduleCalendar;
use Dtrms\Services\WeeklyCompliance;

final class StaffRecordsController
{
    public const PATH = '/reports/staff-record';

    public function __construct(
        private SubmissionMonitoring $monitor,
        private StaffWorkSchedules $schedules,
        private StaffLeaves $leaves,
        private UserAccount $users,
        private WeeklySchedules $weekly,
        private BrowserSession $browser,
        private string $organization
    ) {
    }

    public function profile(array $actor): string
    {
        $scope = self::scope($actor);
        if ($scope === null) {
            return $this->error(403, 'You cannot view staff reporting records.');
        }
        $staff = $this->staff($actor, $scope, $_GET);
        if (!$staff) {
            return $this->error(404, 'This staff record is unavailable.');
        }
        $now = new DateTimeImmutable();
        $error = null;
        try {
            $period = MonitoringFilters::period($_GET, $now);
        } catch (DomainException $exception) {
            http_response_code(422);
            $error = $exception->getMessage();
            $period = MonitoringFilters::period([], $now);
        }
        $id = (int) $staff['id'];
        $lastWeek = $period['until']->modify('monday this week');
        $lastMonth = $period['until']->modify('first day of this month');
        $dailyLinks = match ($scope) {
            'staff' => '/staff/reports/view?id=',
            'supervisor' => '/supervisor/report?id=',
            'hr' => Access::permits($actor, HrReviews::READ, 'hr_manager')
                ? SubmissionMonitoringController::PATH . '/report?staff=' . $id . '&id=' : null,
        };
        return View::render('records/profile', $this->periodData($staff, $period, $now, false) + [
            'title' => 'Staff reporting profile', 'principal' => $actor, 'csrf' => $this->browser->csrf(),
            'error' => $error, 'message' => null, 'scope' => $scope, 'dailyLink' => $dailyLinks,
            'canExport' => ReportingAccess::exports($actor),
            'weeks' => $this->monitor->history($id, $lastWeek->modify('-11 weeks'), $lastWeek->modify('+6 days'), $now, 'week'),
            'months' => $this->monitor->history($id, $lastMonth->modify('-5 months'), $lastMonth->modify('last day of this month'), $now, 'month'),
            'leaves' => $this->monitor->leaves($id, $scope !== 'supervisor'),
            'finalized' => $scope === 'hr' ? $this->weekly->finalHistory($id,
                WeeklyCompliance::week($period['from'])->format('Y-m-d'),$period['until']->format('Y-m-d')) : [],
        ]);
    }

    public function export(array $actor): string
    {
        $scope = self::scope($actor);
        if ($scope === null || !ReportingAccess::exports($actor)) {
            return $this->error(403, 'You cannot export staff reporting records.');
        }
        $staff = $this->staff($actor, $scope, $_POST);
        if (!$staff) {
            return $this->error(404, 'This staff record is unavailable.');
        }
        $format = $_POST['format'] ?? '';
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return $this->error(422, 'Choose PDF or Excel.');
        }
        if (($format === 'pdf' && !class_exists(\Dompdf\Dompdf::class))
            || ($format === 'xlsx' && !class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))) {
            return $this->error(503, 'The export library is unavailable.');
        }
        $now = new DateTimeImmutable();
        try {
            $period = MonitoringFilters::period($_POST, $now);
            $bundle = $this->periodData($staff, $period, $now, true);
            $bytes = $format === 'pdf' ? StaffPeriodExports::pdf($bundle, $this->organization) : StaffPeriodExports::xlsx($bundle);
        } catch (DomainException $exception) {
            return $this->error(422, $exception->getMessage());
        }
        if ($scope === 'supervisor' && !$this->staff($actor, $scope, $_POST)) {
            return $this->error(404, 'This staff record is unavailable.');
        }

        $from = $period['from']->format('Y-m-d');
        $until = $period['until']->format('Y-m-d');
        $this->users->audit('staff_period_exported', (int) $actor['id'], (int) $staff['id'], 'user', (int) $staff['id'], [
            'format' => $format, 'period' => $period['mode'], 'from' => $from, 'until' => $until,
        ]);
        header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: attachment; filename="staff-' . $period['mode'] . '-' . $from . '.' . $format . '"');
        return $bytes;
    }

    private function periodData(array $staff, array $period, DateTimeImmutable $now, bool $includeWork): array
    {
        $id = (int) $staff['id'];
        $today = $now->format('Y-m-d');
        $week=WeeklyCompliance::week($now)->format('Y-m-d');
        if ($week >= $this->weekly->start()) {
            $selection=$this->weekly->get($id,$week);
            $staff['schedule']=in_array($selection['status'] ?? '',WeeklySchedules::CONFIRMED,true)
                ? implode(', ',$selection['days']) : 'Schedule Not Submitted';
        } else {
            $dayList = (new WorkScheduleCalendar($this->schedules->calendar()))->daysOn($today,
                $this->schedules->periods([$id], $today, $today)[$id] ?? []);
            $staff['schedule'] = implode(', ', array_map(static fn (int $day): string => OperationalPolicy::DAYS[$day], $dayList));
        }
        $activeLeaves = $this->leaves->approvedSpans([$id], $today, $today)[$id] ?? [];
        $staff['leave_until'] = $activeLeaves === [] ? null : max(array_column($activeLeaves, 'end_date'));
        $from = $period['from']->format('Y-m-d');
        $until = $period['until']->format('Y-m-d');
        $reportRows = $this->monitor->reports($id, $from, $until, $includeWork);
        $minutes = array_column($reportRows, 'working_minutes');
        return [
            'period' => $period, 'staff' => $staff,
            'current' => $this->monitor->dashboard(['staff' => $id], $period['from'], $period['until'], $now)['rows'][0],
            'states' => SubmissionMonitoring::STATES, 'reports' => $reportRows,
            'approvals' => $this->monitor->approvals($id, $from, $until, $includeWork),
            'exemptions' => $this->monitor->exemptionDates($id, $period['from'], $period['until'], $now),
            'attendance' => ['count' => count(array_filter($minutes, static fn (mixed $value): bool => $value !== null)), 'minutes' => array_sum($minutes)],
        ];
    }

    private static function scope(array $actor): ?string
    {
        return match (true) {
            Access::permits($actor, 'staff_workspace', 'staff') => 'staff',
            Access::permits($actor, 'supervisor_workspace', 'supervisor') => 'supervisor',
            Access::permits($actor, SubmissionMonitoringController::PERMISSION, SubmissionMonitoringController::ROLE) => 'hr',
            default => null,
        };
    }

    private function staff(array $actor, string $scope, array $input): ?array
    {
        $actorId = (int) $actor['id'];
        $id = array_key_exists('staff', $input) ? (int) filter_var($input['staff'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]) : ($scope === 'staff' ? $actorId : 0);
        if (!$id || ($scope === 'staff' && $id !== $actorId)) {
            return null;
        }
        return $this->monitor->person($id, $scope === 'supervisor' ? $actorId : null);
    }

    private function error(int $status, string $message): string
    {
        http_response_code($status);
        return View::render('error', ['title' => 'Staff record unavailable', 'message' => $message]);
    }
}
