<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use Dtrms\Core\Database;
use Dtrms\Models\HrReviews;
use Dtrms\Models\Notifications;
use Dtrms\Models\StaffWorkSchedules;
use PDO;

final class ReportReminders
{
    public function __construct(private PDO $db, private Notifications $notifications)
    {
    }

    public function schedule(DateTimeImmutable $now, array $policy): int
    {
        if (!$policy['reminders_enabled']) {
            return 0;
        }
        $minute = self::minutes($now->format('H:i'));
        $start = self::minutes($policy['reminder_time']);
        if ($minute < $start) {
            return 0;
        }
        $date = $now->format('Y-m-d');
        $weekday = (int) $now->format('N');
        $reviewDay = in_array($weekday, $policy['working_days'], true);
        $schedules = new StaffWorkSchedules($this->db);
        $baseline = $schedules->calendar();
        $legacyDay = $date < (new \Dtrms\Models\WeeklySchedules($this->db))->start();
        if (!$reviewDay && $date < $baseline['effective_from']) {
            return 0;
        }
        $interval = $policy['reminder_interval_minutes'];
        $period = $date . ':' . ($start + intdiv($minute - $start, $interval) * $interval);

        return Database::transaction($this->db, function () use ($minute, $date, $weekday, $period, $policy, $reviewDay, $schedules, $baseline, $legacyDay): int {
            $total = 0;
            if ($legacyDay && $minute >= self::minutes($policy['report_deadline'])) {
                $missing = $this->db->prepare("SELECT u.id, u.created_at FROM users u JOIN roles r ON r.id = u.role_id AND r.code = 'staff'
                    JOIN departments d ON d.id = u.department_id AND d.is_active
                    WHERE u.is_active AND u.staff_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM daily_reports r WHERE r.staff_id = u.id AND r.report_date = ?
                            AND (r.submitted_at IS NOT NULL OR r.status <> 'draft')
                    ) AND NOT EXISTS (
                        SELECT 1 FROM staff_leaves l WHERE l.user_id = u.id AND l.status = 'approved'
                            AND ? BETWEEN l.start_date AND l.end_date
                    ) AND NOT EXISTS (
                        SELECT 1 FROM work_holidays h WHERE h.holiday_date = ?
                            AND h.department_scope IN (0, u.department_id)
                    )");
                $missing->execute([$date, $date, $date]);
                $staff = $missing->fetchAll();
                if ($date >= $baseline['effective_from']) {
                    $calendar = new WorkScheduleCalendar($baseline);
                    $periods = $schedules->periods(array_column($staff, 'id'), $date, $date);
                    $staff = array_filter($staff, static fn (array $person): bool => report_local_date($person['created_at']) <= $date
                        && $calendar->expects($date, $weekday, $periods[$person['id']] ?? []));
                }
                $total += $this->publish(array_column($staff, 'id'), 'reminder_missing_report', $date);
            }

            if (!$reviewDay) {
                return $total;
            }

            $supervisors = $this->db->query("SELECT DISTINCT u.id FROM users u JOIN roles role ON role.id = u.role_id AND role.code = 'supervisor'
                JOIN role_permissions rp ON rp.role_id = role.id JOIN permissions p ON p.id = rp.permission_id AND p.code = 'supervisor_workspace'
                JOIN supervisor_assignments a ON a.supervisor_id = u.id AND a.ended_at IS NULL
                JOIN daily_reports r ON r.staff_id = a.staff_id AND r.status = 'submitted'
                JOIN report_revisions v ON v.report_id = r.id AND v.revision_number = (
                    SELECT MAX(x.revision_number) FROM report_revisions x WHERE x.report_id = r.id
                )
                WHERE u.is_active AND a.staff_id <> u.id AND NOT EXISTS (
                    SELECT 1 FROM report_approvals s WHERE s.report_id = r.id AND s.stage = 'supervisor' AND s.revision_number = v.revision_number
                )")->fetchAll(PDO::FETCH_COLUMN);
            $total += $this->publish($supervisors, 'reminder_supervisor', $period);

            if ((new HrReviews($this->db))->pendingCount() > 0) {
                $hr = $this->db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                    JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
                    WHERE u.is_active AND r.code IN ('hr_officer','hr_manager') AND p.code IN (?, ?)
                    GROUP BY u.id HAVING COUNT(*) = 2");
                $hr->execute([HrReviews::READ, HrReviews::DECIDE]);
                $total += $this->publish($hr->fetchAll(PDO::FETCH_COLUMN), 'reminder_hr', $period);
            }
            return $total;
        });
    }

    private function publish(array $recipients, string $event, string $period): int
    {
        $created = 0;
        foreach ($recipients as $recipient) {
            $created += (int) $this->notifications->publish((int) $recipient, $event, null, $period);
        }
        return $created;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
