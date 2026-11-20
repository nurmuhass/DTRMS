<?php
declare(strict_types=1);

namespace Dtrms\Models;

use DateTimeImmutable;
use DateTimeZone;
use Dtrms\Services\WorkScheduleCalendar;
use PDO;

final class SubmissionMonitoring
{
    public const STATES = ['complete' => 'Complete', 'progress' => 'In Progress', 'incomplete' => 'Incomplete',
        'none' => 'Not Submitted', 'leave' => 'On Leave', 'not_due' => 'Not Yet Due',
        'schedule_missing' => 'Schedule Not Submitted', 'missed' => 'Missed Weekly Deadline'];
    public const SORTS = ['name' => 'Staff name', 'outstanding' => 'Most outstanding', 'compliance' => 'Highest compliance'];
    private const SUBMITTED = ['submitted','supervisor_approved','pending_hr','hr_approved','returned','returned_by_supervisor','returned_by_hr'];
    private const TOTALS = "SUM(expected_day) AS expected, SUM(actual) AS actual, SUM(expected_day * qualifying) AS submitted,
        SUM(due_day * (1-qualifying)) AS due_outstanding,
        SUM(flexible * week_final * expected_day * (1-qualifying)) AS closed_outstanding,
        COUNT(DISTINCT CASE WHEN flexible AND employed AND schedule_state IS NULL THEN week_start END) AS missing_weeks,
        COUNT(DISTINCT CASE WHEN flexible AND schedule_state IS NOT NULL THEN week_start END) AS confirmed_weeks,
        MAX(flexible) AS flexible, MAX(flexible AND NOT week_final) AS open_period,
        GROUP_CONCAT(CASE WHEN flexible AND scheduled THEN work_date END ORDER BY work_date SEPARATOR ', ') AS selected_dates,
        GROUP_CONCAT(CASE WHEN flexible AND scheduled AND selected_due THEN work_date END ORDER BY work_date SEPARATOR ', ') AS passed_dates,
        GROUP_CONCAT(CASE WHEN exempt_day THEN work_date END ORDER BY work_date SEPARATOR ', ') AS exemption_dates,
        GROUP_CONCAT(DISTINCT schedule_state ORDER BY schedule_state SEPARATOR ', ') AS schedule_states,
        SUM(future_day) AS future_days, SUM(leave_day) AS leave_days, SUM(exempt_day) AS exempt_days,
        SUM(hr_done) AS approved, SUM(returned_report) AS returned, MAX(on_leave_today) AS on_leave_today";

    public function __construct(private PDO $db, private StaffWorkSchedules $schedules, private string $deadline)
    {
    }

    public function dashboard(array $filters, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, bool $export = false): array
    {
        [$sql, $args] = $this->cte($filters, $from, $to, $now);
        $sql .= ', totals AS (
            SELECT user_id, ' . self::TOTALS . ' FROM day_counts GROUP BY user_id
        ), classified AS (
            SELECT roster.*, t.*, GREATEST(0,t.expected-t.submitted) AS outstanding,
                CASE
                    WHEN t.missing_weeks > 0 THEN \'schedule_missing\'
                    WHEN t.closed_outstanding > 0 THEN \'missed\'
                    WHEN t.flexible AND t.expected > t.submitted AND t.due_outstanding = 0 THEN \'not_due\'
                    WHEN t.flexible AND t.expected > t.submitted THEN \'progress\'
                    WHEN t.expected > t.submitted AND t.future_days > 0 THEN \'progress\'
                    WHEN t.expected > t.submitted AND t.submitted = 0 THEN \'none\'
                    WHEN t.expected > t.submitted THEN \'incomplete\'
                    WHEN t.on_leave_today OR (t.expected = 0 AND t.future_days = 0 AND t.exempt_days > 0) THEN \'leave\'
                    WHEN t.expected = 0 THEN \'not_due\'
                    WHEN t.future_days > 0 THEN \'progress\'
                    ELSE \'complete\'
                END AS compliance_status,
                ROUND(100 * t.submitted / NULLIF(t.expected,0),1) AS percentage
            FROM roster JOIN totals t ON t.user_id = roster.id
        )';
        $where = [];
        if (empty($filters['staff'])) {
            $where[] = '(is_active OR expected > 0 OR actual > 0 OR leave_days > 0 OR missing_weeks > 0)';
        }
        if (!empty($filters['status'])) {
            $where[] = 'compliance_status = ?';
            $args[] = $filters['status'];
        }
        if (!empty($filters['leave'])) {
            $where[] = $filters['leave'] === 'yes' ? 'leave_days > 0' : 'leave_days = 0';
        }
        if (!empty($filters['schedule'])) {
            $where[] = $filters['schedule'] === 'missing' ? 'missing_weeks > 0' : 'confirmed_weeks > 0';
        }
        $order = match ($filters['sort'] ?? '') {
            'outstanding' => 'outstanding DESC, name, id',
            'compliance' => 'percentage DESC, name, id',
            default => 'name, id',
        };
        $outerOrder = preg_replace('/\b(outstanding|percentage|name|id)\b/', 'page.$1', $order);
        $size = $export ? 2000 : 20;
        $sql .= ', filtered AS (SELECT * FROM classified' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "),
            summary AS (
                SELECT COUNT(*) AS staff_count, COUNT(CASE WHEN is_active THEN 1 END) AS active_count, COALESCE(SUM(expected),0) AS expected_total,
                    COALESCE(SUM(actual),0) AS actual_total, COALESCE(SUM(outstanding),0) AS outstanding_total,
                    COUNT(CASE WHEN compliance_status = 'complete' THEN 1 END) AS complete_total,
                    COUNT(CASE WHEN compliance_status = 'incomplete' THEN 1 END) AS incomplete_total,
                    COUNT(CASE WHEN expected > 0 AND actual = 0 THEN 1 END) AS zero_total,
                    COALESCE(SUM(confirmed_weeks),0) AS schedules_submitted,
                    COALESCE(SUM(missing_weeks),0) AS schedules_missing,
                    COUNT(CASE WHEN closed_outstanding > 0 THEN 1 END) AS missed_total,
                    COUNT(CASE WHEN leave_days > 0 THEN 1 END) AS leave_total
                FROM filtered
            )
            SELECT summary.*, page.* FROM summary LEFT JOIN (
                SELECT * FROM filtered ORDER BY $order LIMIT $size OFFSET ";
        $page = $export ? 1 : min(100000, max(1, (int) ($filters['page'] ?? 1)));
        $read = fn (int $page): array => $this->query($sql . ($size * ($page - 1))
            . ') page ON TRUE ORDER BY ' . $outerOrder, $args);
        $result = $read($page);
        $pages = max(1, (int) ceil($result[0]['staff_count'] / $size));
        if ($page > $pages) {
            $page = $pages;
            $result = $read($page);
        }
        $summary = array_intersect_key($result[0], array_flip(['staff_count','active_count','expected_total','actual_total','outstanding_total',
            'complete_total','incomplete_total','zero_total','leave_total','schedules_submitted','schedules_missing','missed_total']));
        $rows = $result[0]['id'] === null ? [] : $result;
        $count = (int) $summary['staff_count'];
        return compact('summary', 'rows', 'page', 'pages', 'count');
    }

    public function history(int $staff, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now, string $group): array
    {
        [$sql, $args] = $this->cte(['staff' => $staff], $from, $to, $now);
        $period = $group === 'week' ? 'week_start' : 'month_start';
        return $this->query($sql . ', grouped AS (SELECT ' . $period . ' AS period, ' . self::TOTALS
            . ' FROM day_counts GROUP BY ' . $period . ')
            SELECT grouped.*, GREATEST(0,expected-submitted) AS outstanding,
                ROUND(100 * submitted / NULLIF(expected,0),1) AS percentage FROM grouped ORDER BY period DESC', $args);
    }

    private function cte(array $filters, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        $calendar = [];
        $utc = new DateTimeZone('UTC');
        [$hour, $minute] = array_map('intval', explode(':', $this->deadline));
        for ($day = $from; $day <= $to; $day = $tomorrow) {
            $tomorrow = $day->modify('+1 day');
            $week = \Dtrms\Services\WeeklyCompliance::week($day);
            $final = \Dtrms\Services\WeeklyCompliance::deadline($week);
            $calendar[] = [
                'day' => $day->format('Y-m-d'), 'stop' => $tomorrow->setTimezone($utc)->format('Y-m-d H:i:s'),
                'deadline' => $day->setTime($hour, $minute)->setTimezone($utc)->format('Y-m-d H:i:s'),
                'week' => $week->format('Y-m-d'), 'month' => $day->format('Y-m-01'),
                'final' => \Dtrms\Services\WeeklyCompliance::utc($final),
                'closed' => (int) ($now > $final), 'due' => (int) \Dtrms\Services\WeeklyCompliance::due($day,$now),
            ];
        }
        $baseline = $this->schedules->calendar();
        $default = (new WorkScheduleCalendar($baseline))->defaultDays();
        $cutoff = $now->format('H:i') < $this->deadline ? $now->modify('-1 day') : $now;
        $args = [json_encode($calendar, JSON_THROW_ON_ERROR), $cutoff->format('Y-m-d'), $now->format('Y-m-d'),
            $baseline['effective_from'], json_encode($baseline['working_days'], JSON_THROW_ON_ERROR), json_encode($default, JSON_THROW_ON_ERROR),
            (new WeeklySchedules($this->db))->start() ?: '9999-12-31'];
        $where = "role.code = 'staff'";
        if (!empty($filters['ids'])) {
            $where .= ' AND u.id IN (' . implode(',', array_fill(0,count($filters['ids']),'?')) . ')';
            array_push($args,...$filters['ids']);
        }
        foreach (['staff' => 'u.id', 'department' => 'u.department_id', 'supervisor' => 'a.supervisor_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where .= " AND $column = ?";
                $args[] = $filters[$key];
            }
        }
        if (!empty($filters['q'])) {
            $like = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (u.name LIKE ? ESCAPE '!' OR u.staff_id LIKE ? ESCAPE '!')";
            array_push($args, $like, $like);
        }

        $sql = "WITH dates AS (
            SELECT * FROM JSON_TABLE(?, '$[*]' COLUMNS (
                work_date DATE PATH '$.day', stop_utc DATETIME PATH '$.stop', deadline_utc DATETIME PATH '$.deadline',
                week_start DATE PATH '$.week', month_start DATE PATH '$.month', final_utc DATETIME PATH '$.final',
                week_final INT PATH '$.closed', selected_due INT PATH '$.due'
            )) AS date_rows
        ), settings AS (
            SELECT CAST(? AS DATE) AS cutoff, CAST(? AS DATE) AS today, CAST(? AS DATE) AS schedule_start,
                CAST(? AS JSON) AS legacy_days, CAST(? AS JSON) AS default_days, CAST(? AS DATE) AS flexible_start
        ), roster AS (
            SELECT u.id,u.name,u.staff_id,u.is_active,u.created_at,u.department_id,d.name AS department,
                supervisor.name AS supervisor
            FROM users u JOIN roles role ON role.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN supervisor_assignments a ON a.current_staff_id = u.id
            LEFT JOIN users supervisor ON supervisor.id = a.supervisor_id WHERE $where
        ), facts AS (
            SELECT roster.id AS user_id, c.work_date,c.week_start,c.month_start, settings.cutoff, settings.today,
                c.week_start>=settings.flexible_start AS flexible, c.week_final,c.selected_due,
                IF(ws.id IS NULL,NULL,IF(ws.unlocked_at IS NOT NULL,'HR unlocked',
                    IF(ws.week_start<=settings.today,'locked',ws.status))) AS schedule_state,
                COALESCE(roster.created_at < c.stop_utc,0) * COALESCE(
                    (SELECT h.is_active FROM user_account_status_history h
                        WHERE h.user_id=roster.id AND h.changed_at <= IF(c.week_start>=settings.flexible_start,
                            c.stop_utc - INTERVAL 1 SECOND,c.deadline_utc) ORDER BY h.changed_at DESC,h.id DESC LIMIT 1),
                    (SELECT NOT h.is_active FROM user_account_status_history h
                        WHERE h.user_id=roster.id ORDER BY h.changed_at,h.id LIMIT 1),
                    roster.is_active) AS employed,
                CASE
                    WHEN c.week_start>=settings.flexible_start THEN wd.id IS NOT NULL
                    WHEN EXISTS (SELECT 1 FROM staff_work_schedules s WHERE s.user_id = roster.id AND s.status = 'assigned'
                        AND s.effective_from <= c.work_date AND (s.effective_to IS NULL OR s.effective_to >= c.work_date)
                        AND s.day_of_week = WEEKDAY(c.work_date) + 1) THEN 1
                    WHEN EXISTS (SELECT 1 FROM staff_work_schedules s WHERE s.user_id = roster.id AND s.status = 'assigned'
                        AND s.effective_from <= c.work_date AND (s.effective_to IS NULL OR s.effective_to >= c.work_date)) THEN 0
                    ELSE JSON_CONTAINS(IF(c.work_date < settings.schedule_start,settings.legacy_days,settings.default_days),
                        CAST(WEEKDAY(c.work_date) + 1 AS JSON))
                END AS scheduled,
                c.week_start<settings.flexible_start AND EXISTS (SELECT 1 FROM work_holidays h WHERE h.holiday_date = c.work_date
                    AND h.department_scope IN (0,COALESCE(roster.department_id,0))) AS holiday,
                EXISTS (SELECT 1 FROM staff_leaves l WHERE l.user_id = roster.id AND l.status = 'approved'
                    AND l.start_date <= c.work_date AND l.end_date >= c.work_date) AS on_leave,
                COALESCE(r.status IN (" . self::submittedSql() . "),0) AS actual,
                COALESCE(r.status IN (" . self::submittedSql() . ") AND (c.week_start<settings.flexible_start
                    OR COALESCE(r.first_submitted_at,r.submitted_at)<=c.final_utc),0) AS qualifying,
                COALESCE(r.status = 'hr_approved',0) AS hr_done,
                COALESCE(r.status IN ('returned','returned_by_hr','returned_by_supervisor'),0) AS returned_report
            FROM roster CROSS JOIN dates c CROSS JOIN settings
            LEFT JOIN staff_weekly_schedules ws ON ws.user_id=roster.id AND ws.week_start=c.week_start
                AND ws.status IN ('submitted','approved','locked')
            LEFT JOIN staff_weekly_schedule_days wd ON wd.weekly_schedule_id=ws.id AND wd.scheduled_date=c.work_date
            LEFT JOIN daily_reports r ON r.staff_id = roster.id AND r.report_date = c.work_date
        ), day_counts AS (
            SELECT user_id, work_date, week_start, month_start, actual, qualifying, hr_done, returned_report,
                flexible, employed, scheduled, week_final, selected_due, schedule_state,
                employed * scheduled * (1-holiday) * (1-on_leave) * (flexible OR work_date<=cutoff) AS expected_day,
                employed * scheduled * (1-holiday) * (1-on_leave) * IF(flexible,selected_due,work_date<=cutoff) AS due_day,
                employed * scheduled * (1-holiday) * (1-on_leave) * (1-IF(flexible,selected_due,work_date<=cutoff)) AS future_day,
                employed * on_leave AS leave_day, employed * scheduled * (1-holiday) * on_leave AS exempt_day,
                employed * on_leave * (work_date = today) AS on_leave_today
            FROM facts
        )";
        return [$sql, $args];
    }

    public function person(int $staff, ?int $supervisor = null): ?array
    {
        return $this->query("SELECT u.id,u.name,u.staff_id,u.is_active,d.name AS department, supervisor.name AS supervisor FROM users u
            JOIN roles r ON r.id=u.role_id AND r.code='staff' LEFT JOIN departments d ON d.id=u.department_id
            LEFT JOIN supervisor_assignments a ON a.current_staff_id=u.id
            LEFT JOIN users supervisor ON supervisor.id=a.supervisor_id
            WHERE u.id=?" . ($supervisor === null ? '' : ' AND a.supervisor_id=? AND u.id<>a.supervisor_id'),
            $supervisor === null ? [$staff] : [$staff, $supervisor])[0] ?? null;
    }

    public function exemptionDates(int $staff, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        [$sql, $args] = $this->cte(['staff' => $staff], $from, $to, $now);
        return array_column($this->query($sql . ' SELECT work_date FROM day_counts WHERE exempt_day=1 ORDER BY work_date', $args), 'work_date');
    }

    public function flexibleMonths(array $ids, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        $result = [];
        foreach (array_chunk($ids,500) as $batch) {
            [$sql,$args] = $this->cte(['ids'=>$batch],$from,$to,$now);
            foreach ($this->query($sql . ' SELECT user_id,month_start,SUM(expected_day) AS expected,
                SUM(expected_day*qualifying) AS fulfilled,SUM(due_day*(1-qualifying)) AS due_outstanding
                FROM day_counts WHERE flexible GROUP BY user_id,month_start', $args) as $row) {
                $result[$row['user_id']][substr($row['month_start'],0,7)] = $row;
            }
        }
        return $result;
    }

    public function selectedMissingDates(DateTimeImmutable $from,DateTimeImmutable $to,DateTimeImmutable $now): array
    {
        [$sql,$args]=$this->cte([],$from,$to,$now);
        return $this->query($sql . ' SELECT user_id,work_date FROM day_counts JOIN roster ON roster.id=user_id
            WHERE flexible AND roster.is_active AND expected_day=1 AND qualifying=0', $args);
    }

    public function reports(int $staff, string $from, string $to, bool $includeWork = false): array
    {
        $work = $includeWork ? ",
            CASE WHEN v.id IS NULL THEN r.content WHEN v.snapshot IS NULL THEN v.content
                ELSE NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot,'$.content')),'null') END AS content,
            IF(v.id IS NULL,r.achievement,NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot,'$.achievement')),'null')) AS achievement,
            IF(v.id IS NULL,r.challenges,NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot,'$.challenges')),'null')) AS challenges" : '';
        return $this->query("SELECT r.id,r.reference_number,r.report_date,r.status,r.submitted_at,r.first_submitted_at,
            COALESCE(r.department_name_snapshot,d.name) AS department,
            IF(v.id IS NULL,r.working_minutes,v.working_minutes) AS working_minutes,
            IF(v.id IS NULL,r.arrival_time,NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot,'$.arrival_time')),'null')) AS arrival_time,
            IF(v.id IS NULL,r.work_start_time,NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot,'$.work_start_time')),'null')) AS work_start_time,
            IF(v.id IS NULL,r.work_end_time,NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot,'$.work_end_time')),'null')) AS work_end_time" . $work . "
            FROM daily_reports r JOIN departments d ON d.id=r.department_id
            LEFT JOIN report_revisions v ON v.report_id=r.id AND v.revision_number=(
                SELECT MAX(x.revision_number) FROM report_revisions x WHERE x.report_id=r.id AND x.submitted_at IS NOT NULL
            )
            WHERE r.staff_id=? AND r.report_date BETWEEN ? AND ? AND r.status IN (" . self::submittedSql() . ')
            ORDER BY r.report_date DESC, r.id DESC', [$staff,$from,$to]);
    }

    public function approvals(int $staff, string $from, string $to, bool $includeComments = false): array
    {
        return $this->query("SELECT r.id,r.reference_number,r.report_date,a.revision_number,a.stage,a.decision,a.created_at,
            u.name AS reviewer" . ($includeComments ? ',a.comments' : '') . "
            FROM daily_reports r JOIN report_approvals a ON a.report_id=r.id JOIN users u ON u.id=a.reviewer_id
            WHERE r.staff_id=? AND r.report_date BETWEEN ? AND ? AND r.status IN (" . self::submittedSql() . ')
            ORDER BY r.report_date DESC,a.revision_number DESC,a.created_at DESC,a.id DESC', [$staff,$from,$to]);
    }

    public function leaves(int $staff, bool $includePrivateTypes = true): array
    {
        return $this->query('SELECT id,' . ($includePrivateTypes ? 'leave_type,' : '') . 'start_date,end_date,status FROM staff_leaves
            WHERE user_id=?' . ($includePrivateTypes ? '' : " AND status='approved'")
            . ' ORDER BY start_date DESC,id DESC LIMIT 30', [$staff]);
    }

    public static function isSubmitted(string $status): bool
    {
        return in_array($status, self::SUBMITTED, true);
    }

    private static function submittedSql(): string
    {
        return "'" . implode("','", self::SUBMITTED) . "'";
    }

    private function query(string $sql, array $args): array
    {
        $query = $this->db->prepare($sql);
        $query->execute($args);
        return $query->fetchAll();
    }
}
