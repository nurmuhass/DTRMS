<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\Database;
use Dtrms\Middleware\Access;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\UserAccount;
use PDO;

final class StaffScheduleManagement
{
    public function __construct(private PDO $db, private StaffWorkSchedules $schedules)
    {
    }

    public function save(array $input, array $actor, DateTimeImmutable $today): int
    {
        if (!Access::permits($actor, 'hr_manage_organization', 'hr_manager')) {
            throw new DomainException('You cannot manage staff schedules.', 403);
        }
        $ids = self::numbers($input['staff'] ?? null, 50, PHP_INT_MAX);
        $days = self::numbers($input['days'] ?? null, 7, 7);
        $from = $input['effective_from'] ?? '';
        $to = $input['effective_to'] ?? '';
        $replacement = $input['replace'] ?? '';
        $reason = $input['reason'] ?? '';
        $exception = ($input['exception'] ?? '') === '1';
        if (!is_string($from) || !DailyReporting::validDate($from) || $from <= $today->format('Y-m-d')
            || !is_string($to) || ($to !== '' && (!DailyReporting::validDate($to) || $to < $from))) {
            throw new DomainException('Choose a future start date and an optional end date on or after it.');
        }
        if (!is_string($replacement) || ($replacement !== '' && (count($ids) !== 1 || !preg_match('/\A[a-f0-9]{32}\z/', $replacement)))) {
            throw new DomainException('Choose a valid planned schedule to edit.');
        }
        if (!is_string($reason) || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 500
            || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new DomainException('Enter a valid exception reason up to 500 characters.');
        }
        $reason = trim($reason);
        if (count($days) !== WorkScheduleCalendar::WEEKLY_DAYS && (!$exception || preg_match('/[^\p{Z}\s]/u', $reason) !== 1)) {
            throw new DomainException('Select exactly three days, or explicitly authorize an exception and give its reason.');
        }
        $reason = count($days) === WorkScheduleCalendar::WEEKLY_DAYS ? null : $reason;
        if ($from >= (new \Dtrms\Models\WeeklySchedules($this->db))->start()) {
            throw new DomainException('Use Weekly schedules for dates after the flexible schedule rollout.',409);
        }

        return Database::transaction($this->db, function () use ($ids, $days, $from, $to, $replacement, $reason, $actor, $today): int {
            foreach ($ids as $id) {
                $staff = $this->schedules->staff($id, true);
                if (!$staff || !$staff['is_active'] || $staff['staff_id'] === null) {
                    throw new DomainException('Every selected account must be active and have a staff ID.');
                }
                $previousTo = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
                $existing = $this->schedules->query("SELECT id, assignment_key, effective_from, effective_to, day_of_week
                    FROM staff_work_schedules WHERE user_id = ? AND status = 'assigned'
                    AND ((effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)) OR assignment_key = ?)
                    ORDER BY effective_from, day_of_week FOR UPDATE", [$id, $to ?: '9999-12-31', $from, $replacement])->fetchAll();
                $cancelled = [];
                $shortened = [];
                foreach ($existing as $row) {
                    if ($row['assignment_key'] === $replacement && $row['effective_from'] > $today->format('Y-m-d')) {
                        $cancelled[] = $row;
                    } elseif ($row['effective_from'] < $from && $row['effective_from'] <= $today->format('Y-m-d')) {
                        $shortened[] = $row;
                    } else {
                        throw new DomainException('A planned schedule overlaps the requested period. Edit the planned schedule first.', 409);
                    }
                }
                if ($replacement !== '' && $cancelled === []) {
                    throw new DomainException('The planned schedule changed. Reload its history and try again.', 409);
                }
                if ($cancelled !== []) {
                    $this->schedules->query("UPDATE staff_work_schedules SET status = 'cancelled'
                        WHERE user_id = ? AND assignment_key = ? AND status = 'assigned'", [$id, $replacement]);
                }
                foreach ($shortened as $row) {
                    $this->schedules->query('UPDATE staff_work_schedules SET effective_to = ? WHERE id = ?', [$previousTo, $row['id']]);
                }
                $assignment = bin2hex(random_bytes(16));
                $insert = $this->db->prepare('INSERT INTO staff_work_schedules
                    (user_id, assignment_key, day_of_week, effective_from, effective_to, exception_reason, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($days as $day) {
                    $insert->execute([$id, $assignment, $day, $from, $to ?: null, $reason, $actor['id']]);
                }
                (new UserAccount($this->db))->audit('staff_work_schedule_saved', (int) $actor['id'], $id, 'user', $id, [
                    'assignment' => $assignment, 'days' => $days, 'effective_from' => $from, 'effective_to' => $to ?: null,
                    'exception_reason' => $reason, 'replaced' => $replacement ?: null,
                    'previous_periods' => $shortened,
                ]);
            }
            return count($ids);
        });
    }

    private static function numbers(mixed $input, int $limit, int $max): array
    {
        if (!is_array($input) || $input === [] || count($input) > $limit) {
            throw new DomainException('Choose at least one staff member and working day. Select at most 50 staff at a time.');
        }
        $values = [];
        foreach ($input as $value) {
            $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
            if ($number === false || isset($values[$number])) {
                throw new DomainException('Select distinct, valid staff members and working days.');
            }
            $values[$number] = $number;
        }
        sort($values);
        return $values;
    }
}
