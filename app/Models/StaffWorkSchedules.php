<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;
use Dtrms\Core\SchemaUnavailable;

final class StaffWorkSchedules
{
    public function __construct(private PDO $db)
    {
    }

    public function calendar(): array
    {
        $value = $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'staff_schedule_calendar'")->fetchColumn();
        if (!is_string($value)) {
            throw new SchemaUnavailable('Missing staff_schedule_calendar baseline. Apply the staff schedule migrations.');
        }
        return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
    }

    public function periods(array $ids, ?string $from = null, ?string $until = null): array
    {
        $periods = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $markers = implode(',', array_fill(0, count($batch), '?'));
            $where = $from === null ? '' : " AND s.status = 'assigned' AND s.effective_from <= ?
                AND (s.effective_to IS NULL OR s.effective_to >= ?)";
            $args = $from === null ? $batch : [...$batch, $until, $from];
            $rows = $this->query("SELECT s.id, s.user_id, s.assignment_key, s.day_of_week, s.effective_from, s.effective_to, s.status,
                    s.exception_reason, s.created_at, s.created_by
                FROM staff_work_schedules s WHERE s.user_id IN ($markers)" . $where
                . ' ORDER BY s.effective_from DESC, s.id DESC', $args)->fetchAll();
            foreach ($rows as $row) {
                $key = $row['assignment_key'];
                $periods[$row['user_id']][$key] ??= $row + ['days' => []];
                $periods[$row['user_id']][$key]['days'][] = (int) $row['day_of_week'];
            }
        }
        foreach ($periods as &$userPeriods) {
            $userPeriods = array_values($userPeriods);
            foreach ($userPeriods as &$period) {
                sort($period['days']);
            }
            unset($period);
        }
        unset($userPeriods);
        return $periods;
    }

    public function staff(int $id, bool $lock = false): ?array
    {
        return $this->query("SELECT u.id, u.name, u.staff_id, u.is_active FROM users u
            WHERE u.id = ? AND u.role_id = (SELECT id FROM roles WHERE code = 'staff')" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch() ?: null;
    }

    public function query(string $sql, array $args = []): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($args);
        return $query;
    }
}
