<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;
use PDOStatement;

final class Organization
{
    public const PAGE_SIZE = 20;
    private const USER_COLUMNS = 'u.id, u.staff_id, u.name, u.email, u.department_id, u.is_active, u.must_change_password,
        r.code AS role_code, r.name AS role_name, d.name AS department, s.name AS supervisor, a.supervisor_id';
    private const USER_SCOPE = ' FROM users u JOIN roles r ON r.id = u.role_id';
    private const USER_JOINS = self::USER_SCOPE . '
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN supervisor_assignments a ON a.current_staff_id = u.id
        LEFT JOIN users s ON s.id = a.supervisor_id';

    public function __construct(private PDO $db)
    {
    }

    public function query(string $sql, array $args = []): PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($args);
        return $query;
    }

    public function metrics(): array
    {
        return $this->query("SELECT
            (SELECT COUNT(*) FROM departments WHERE is_active) AS departments,
            (SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                WHERE u.is_active AND r.code = 'staff') AS staff,
            (SELECT COUNT(*) FROM supervisor_assignments WHERE ended_at IS NULL) AS assignments")->fetch();
    }

    public function departments(string $search, int $page): array
    {
        $needle = self::like($search);
        $where = " WHERE (d.name LIKE ? ESCAPE '!' OR d.code LIKE ? ESCAPE '!')";
        return $this->paginate('d.*, h.name AS hod', ' FROM departments d LEFT JOIN users h ON h.id = d.hod_id',
            $where, [$needle, $needle], 'd.name, d.id', $page);
    }

    public function directory(string $search, string $status, string $role, int $department, int $page, bool $officers): array
    {
        $where = " WHERE r.code IN ('staff','supervisor'" . ($officers ? ",'hr_officer'" : '') . ')';
        $args = [];
        if ($search !== '') {
            $where .= " AND (u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR u.staff_id LIKE ? ESCAPE '!')";
            $args = array_fill(0, 3, self::like($search));
        }
        if ($status !== '') {
            $where .= ' AND u.is_active = ?';
            $args[] = $status === 'active' ? 1 : 0;
        }
        if ($role !== '') {
            $where .= ' AND r.code = ?';
            $args[] = $role;
        }
        if ($department > 0) {
            $where .= ' AND u.department_id = ?';
            $args[] = $department;
        }
        return $this->paginate(self::USER_COLUMNS, self::USER_JOINS, $where, $args, 'u.name, u.id', $page, self::USER_SCOPE);
    }

    public function user(int $id): ?array
    {
        return $this->query('SELECT ' . self::USER_COLUMNS . self::USER_JOINS . ' WHERE u.id = ?', [$id])->fetch() ?: null;
    }

    public function department(int $id): ?array
    {
        return $this->query('SELECT * FROM departments WHERE id = ?', [$id])->fetch() ?: null;
    }

    public function departmentOptions(): array
    {
        return $this->query('SELECT id, code, name, is_active FROM departments ORDER BY name, id')->fetchAll();
    }

    public function hodOptions(int $department): array
    {
        return $this->query("SELECT u.id, u.name, u.staff_id, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.department_id = ? AND u.is_active AND r.code IN ('staff','supervisor','hr_officer')
            ORDER BY u.name, u.id", [$department])->fetchAll();
    }

    public function supervisorOptions(): array
    {
        return $this->query("SELECT u.id, u.name, u.staff_id FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.is_active AND r.code = 'supervisor' ORDER BY u.name, u.id")->fetchAll();
    }

    public function assignmentHistory(int $staff): array
    {
        return $this->query('SELECT a.started_at, a.ended_at, a.change_reason, p.name AS previous_name,
            s.name AS supervisor_name, editor.name AS changed_by
            FROM supervisor_assignments a JOIN users s ON s.id = a.supervisor_id
            JOIN users editor ON editor.id = a.assigned_by
            LEFT JOIN users p ON p.id = a.previous_supervisor_id
            WHERE a.staff_id = ? ORDER BY a.started_at DESC, a.id DESC LIMIT 50', [$staff])->fetchAll();
    }

    private function paginate(string $columns, string $from, string $where, array $args, string $order, int $page, ?string $countFrom = null): array
    {
        $count = (int) $this->query('SELECT COUNT(*)' . ($countFrom ?? $from) . $where, $args)->fetchColumn();
        $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = $this->query('SELECT ' . $columns . $from . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset, $args)->fetchAll();

        return compact('rows', 'count', 'page', 'pages');
    }

    private static function like(string $search): string
    {
        return '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
