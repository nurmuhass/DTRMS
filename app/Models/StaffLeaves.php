<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;
use PDOStatement;

final class StaffLeaves
{
    public const TYPES = [
        'annual' => 'Annual leave', 'sick' => 'Sick leave', 'casual' => 'Casual leave',
        'parental' => 'Maternity/paternity leave', 'official_assignment' => 'Official assignment',
        'other' => 'Other approved leave',
    ];
    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    private const FROM = ' FROM staff_leaves l JOIN users u ON u.id = l.user_id LEFT JOIN departments d ON d.id = u.department_id';

    public function __construct(private PDO $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->query('SELECT l.*, u.name AS staff_name, u.staff_id, d.name AS department,
            creator.name AS creator_name, approver.name AS approver_name' . self::FROM
            . ' JOIN users creator ON creator.id = l.created_by LEFT JOIN users approver ON approver.id = l.approved_by
            WHERE l.id = ?', [$id])->fetch() ?: null;
    }

    public function staffOptions(): array
    {
        return $this->query("SELECT u.id, u.staff_id, u.name FROM users u JOIN roles r ON r.id = u.role_id
            WHERE r.code = 'staff' AND u.staff_id IS NOT NULL ORDER BY u.name, u.id")->fetchAll();
    }

    public function counts(string $today): array
    {
        return $this->query("SELECT COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending,
            COUNT(DISTINCT CASE WHEN status = 'approved' AND ? BETWEEN start_date AND end_date THEN user_id END) AS current,
            COUNT(CASE WHEN status = 'approved' AND start_date > ? THEN 1 END) AS upcoming
            FROM staff_leaves", [$today, $today])->fetch();
    }

    public function listing(string $mode, array $filters, string $today): array
    {
        [$where, $args] = $this->filters($filters);
        $where .= match ($mode) {
            'queue' => " AND l.status = 'pending'",
            'current' => " AND l.status = 'approved' AND ? BETWEEN l.start_date AND l.end_date",
            'upcoming' => " AND l.status = 'approved' AND l.start_date > ?",
            'approved' => " AND l.status = 'approved'",
            'history' => " AND (l.status IN ('rejected','cancelled') OR (l.status = 'approved' AND l.end_date < ?))",
            default => '',
        };
        if (in_array($mode, ['current', 'upcoming', 'history'], true)) {
            $args[] = $today;
        }
        $count = (int) $this->query('SELECT COUNT(*)' . self::FROM . $where, $args)->fetchColumn();
        $pages = max(1, (int) ceil($count / 20));
        $page = min($filters['page'], $pages);
        $order = in_array($mode, ['queue', 'upcoming'], true) ? 'l.start_date ASC, l.id ASC' : 'l.start_date DESC, l.id DESC';
        $rows = $this->query('SELECT l.id, l.leave_type, l.status, l.start_date, l.end_date, u.name AS staff_name,
            u.staff_id, d.name AS department' . self::FROM . $where . ' ORDER BY ' . $order
            . ' LIMIT 20 OFFSET ' . (($page - 1) * 20), $args)->fetchAll();
        return compact('count', 'pages', 'page', 'rows');
    }

    public function approvedSpans(array $ids, string $from, string $until): array
    {
        $spans = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $marks = implode(',', array_fill(0, count($batch), '?'));
            $query = $this->query("SELECT user_id, start_date, end_date FROM staff_leaves WHERE user_id IN ($marks)
                AND status = 'approved' AND start_date <= ? AND end_date >= ?", [...$batch, $until, $from]);
            while ($row = $query->fetch()) {
                $spans[$row['user_id']][] = $row;
            }
        }
        return $spans;
    }

    public static function covers(string $date, array $spans): bool
    {
        foreach ($spans as $span) {
            if ($date >= $span['start_date'] && $date <= $span['end_date']) {
                return true;
            }
        }
        return false;
    }

    public function calendarCounts(array $filters, \DateTimeImmutable $month): array
    {
        $filters['date'] = '';
        [$where, $args] = $this->filters($filters);
        $rows = $this->query("SELECT l.user_id, l.start_date, l.end_date" . self::FROM . $where
            . " AND l.status = 'approved' AND l.start_date <= ? AND l.end_date >= ?",
            [...$args, $month->format('Y-m-t'), $month->format('Y-m-01')]);
        $counts = [];
        while ($row = $rows->fetch()) {
            $start = max($month->format('Y-m-01'), $row['start_date']);
            $end = min($month->format('Y-m-t'), $row['end_date']);
            for ($day = (int) substr($start, 8); $day <= (int) substr($end, 8); $day++) {
                $counts[$day][$row['user_id']] = true;
            }
        }
        return array_map('count', $counts);
    }

    private function filters(array $filters): array
    {
        $where = ' WHERE 1=1';
        $args = [];
        if ($filters['q'] !== '') {
            $name = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (u.name LIKE ? ESCAPE '!' OR u.staff_id LIKE ? ESCAPE '!')";
            $args = [$name, $name];
        }
        if ($filters['department']) {
            $where .= ' AND u.department_id = ?';
            $args[] = $filters['department'];
        }
        if ($filters['date'] !== '') {
            $where .= ' AND ? BETWEEN l.start_date AND l.end_date';
            $args[] = $filters['date'];
        }
        return [$where, $args];
    }

    public function query(string $sql, array $args = []): PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($args);
        return $query;
    }
}
