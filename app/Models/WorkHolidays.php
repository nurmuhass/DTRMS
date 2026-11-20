<?php
declare(strict_types=1);

namespace Dtrms\Models;

use DomainException;
use Dtrms\Core\Database;
use Dtrms\Services\DailyReporting;
use PDO;
use PDOException;

final class WorkHolidays
{
    public function __construct(private PDO $db)
    {
    }

    public function between(string $from, string $to): array
    {
        $query = $this->db->prepare('SELECT h.id, h.holiday_date, h.name, h.department_id, d.name AS department
            FROM work_holidays h LEFT JOIN departments d ON d.id = h.department_id
            WHERE h.holiday_date BETWEEN ? AND ? ORDER BY h.holiday_date, h.department_scope');
        $query->execute([$from, $to]);
        return $query->fetchAll();
    }

    public function dates(string $from, string $to): array
    {
        $days = [];
        foreach ($this->between($from, $to) as $row) {
            $days[$row['department_id'] ?? 0][$row['holiday_date']] = true;
        }
        return $days;
    }

    public function add(mixed $date, mixed $name, int $department, int $actor): void
    {
        if (!is_string($date) || !DailyReporting::validDate($date) || !is_string($name)
            || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 120
            || trim($name) === '' || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new DomainException('Enter a valid holiday date and a name up to 120 characters.');
        }
        try {
            Database::transaction($this->db, function () use ($date, $name, $department, $actor): void {
                $insert = $this->db->prepare('INSERT INTO work_holidays (holiday_date, name, department_id, created_by) VALUES (?, ?, ?, ?)');
                $insert->execute([$date, trim($name), $department ?: null, $actor]);
                (new UserAccount($this->db))->audit('work_holiday_created', $actor, null, 'work_holiday',
                    (int) $this->db->lastInsertId(), ['date' => $date, 'department' => $department ?: null]);
            });
        } catch (PDOException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1452], true)) {
                throw new DomainException('That holiday already exists for the selected scope, or the department is unavailable.');
            }
            throw $error;
        }
    }

    public function remove(int $id, int $actor): void
    {
        Database::transaction($this->db, function () use ($id, $actor): void {
            $entry = $this->db->prepare('SELECT holiday_date, department_id FROM work_holidays WHERE id = ? FOR UPDATE');
            $entry->execute([$id]);
            $row = $entry->fetch();
            if (!$row) {
                throw new DomainException('The holiday was already removed or does not exist.');
            }
            $delete = $this->db->prepare('DELETE FROM work_holidays WHERE id = ?');
            $delete->execute([$id]);
            (new UserAccount($this->db))->audit('work_holiday_removed', $actor, null, 'work_holiday', $id, $row);
        });
    }
}
