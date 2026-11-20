<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;
use PDOStatement;

final class SupervisorReports
{
    private const ASSIGNED_REPORTS = ' FROM daily_reports r JOIN supervisor_assignments a ON a.current_staff_id = r.staff_id
        JOIN users staff ON staff.id = r.staff_id';
    private const REPORT_SCOPE = " WHERE a.supervisor_id = ? AND r.staff_id <> a.supervisor_id AND r.status <> 'draft'";
    private const REPORT_COLUMNS = 'r.id, r.reference_number, r.report_date, r.status, r.submitted_at,
        COALESCE(r.reporter_name, staff.name) AS staff_name, r.reporter_staff_code AS staff_code';

    public function __construct(private PDO $db)
    {
    }

    public function find(int $supervisor, int $id): ?array
    {
        return $this->query('SELECT r.*, COALESCE(r.reporter_name, staff.name) AS staff_name' . self::ASSIGNED_REPORTS
            . self::REPORT_SCOPE . ' AND r.id = ?', [$supervisor, $id])->fetch() ?: null;
    }

    public function counts(int $supervisor): array
    {
        $counts = $this->query("SELECT
            COUNT(CASE WHEN r.status = 'submitted' THEN 1 END) AS pending,
            COUNT(CASE WHEN r.status = 'submitted' AND EXISTS (
                SELECT 1 FROM report_revisions x WHERE x.report_id = r.id
            ) AND NOT EXISTS (
                SELECT 1 FROM report_approvals v WHERE v.report_id = r.id AND v.stage = 'supervisor'
                AND v.revision_number = (SELECT MAX(x.revision_number) FROM report_revisions x WHERE x.report_id = r.id)
            ) THEN 1 END) AS actionable,
            COUNT(CASE WHEN EXISTS (
                SELECT 1 FROM report_approvals v WHERE v.report_id = r.id AND v.reviewer_id = a.supervisor_id
                AND v.stage = 'supervisor' AND v.decision = 'approved'
            ) THEN 1 END) AS approved,
            COUNT(CASE WHEN EXISTS (
                SELECT 1 FROM report_approvals v WHERE v.report_id = r.id AND v.reviewer_id = a.supervisor_id
                AND v.stage = 'supervisor' AND v.decision = 'returned'
            ) THEN 1 END) AS returned"
            . self::ASSIGNED_REPORTS . self::REPORT_SCOPE, [$supervisor])->fetch();
        $counts['staff'] = (int) $this->query(
            'SELECT COUNT(*) FROM supervisor_assignments WHERE supervisor_id = ? AND ended_at IS NULL AND staff_id <> supervisor_id',
            [$supervisor]
        )->fetchColumn();
        return $counts;
    }

    public function listing(int $actor, string $name, string $date, string $status, int $page): array
    {
        [$where, $parameters] = $this->filters($actor, $name, $date);
        if ($status === 'pending_hr') {
            $where .= " AND r.status IN ('pending_hr','supervisor_approved')";
        } elseif ($status !== '') {
            $where .= ' AND r.status = ?';
            $parameters[] = $status;
        }
        return $this->paginate(self::REPORT_COLUMNS, self::ASSIGNED_REPORTS, $where, $parameters,
            'r.report_date DESC, r.id DESC', $page);
    }

    public function decisions(int $actor, string $name, string $date, string $decision, int $page, int $size = 20): array
    {
        [$where, $parameters] = $this->filters($actor, $name, $date);
        $from = self::ASSIGNED_REPORTS . ' JOIN report_approvals v ON v.report_id = r.id';
        $where .= " AND v.stage = 'supervisor' AND v.reviewer_id = ?";
        $parameters[] = $actor;
        if ($decision !== '') {
            $where .= ' AND v.decision = ?';
            $parameters[] = $decision;
        }
        return $this->paginate(self::REPORT_COLUMNS . ', v.decision, v.revision_number, v.created_at AS decided_at', $from,
            $where, $parameters, 'v.created_at DESC, v.id DESC', $page, $size);
    }

    public function staff(int $actor, string $name, int $page): array
    {
        $from = ' FROM supervisor_assignments a JOIN users u ON u.id = a.staff_id
            LEFT JOIN departments d ON d.id = u.department_id';
        $where = " WHERE a.supervisor_id = ? AND a.ended_at IS NULL AND a.staff_id <> a.supervisor_id AND u.name LIKE ? ESCAPE '!'";
        return $this->paginate('u.id, u.name, u.staff_id, u.is_active, d.name AS department, a.started_at', $from,
            $where, [$actor, self::like($name)], 'u.name, u.id', $page);
    }

    public function lock(int $id, int $staff, int $supervisor): ?array
    {
        // HR reassignment and staff edits lock the staff account before changing report eligibility.
        $this->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$staff])->fetchColumn();
        $record = $this->query('SELECT * FROM daily_reports WHERE id = ? AND staff_id = ? FOR UPDATE', [$id, $staff])->fetch();
        if (!$record || $staff === $supervisor) {
            return null;
        }
        $authorized = $this->query("SELECT a.id FROM supervisor_assignments a
            JOIN users u ON u.id = a.supervisor_id AND u.is_active AND u.must_change_password = FALSE
            JOIN roles r ON r.id = u.role_id AND r.code = 'supervisor'
            JOIN role_permissions rp ON rp.role_id = r.id
            JOIN permissions p ON p.id = rp.permission_id AND p.code = 'supervisor_workspace'
            WHERE a.current_staff_id = ? AND a.supervisor_id = ? LOCK IN SHARE MODE", [$staff, $supervisor])->fetchColumn();
        return $authorized ? $record : null;
    }

    private function filters(int $actor, string $name, string $date): array
    {
        $where = self::REPORT_SCOPE;
        $parameters = [$actor];
        if ($name !== '') {
            $where .= " AND (staff.name LIKE ? ESCAPE '!' OR r.reporter_name LIKE ? ESCAPE '!')";
            $parameters[] = self::like($name);
            $parameters[] = self::like($name);
        }
        if ($date !== '') {
            $where .= ' AND r.report_date = ?';
            $parameters[] = $date;
        }
        return [$where, $parameters];
    }

    private function paginate(string $columns, string $from, string $where, array $parameters, string $order, int $page, int $size = 20): array
    {
        $count = (int) $this->query('SELECT COUNT(*)' . $from . $where, $parameters)->fetchColumn();
        $pages = max(1, (int) ceil($count / $size));
        $page = min($page, $pages);
        $rows = $this->query('SELECT ' . $columns . $from . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), $parameters)->fetchAll();
        return compact('rows', 'count', 'page', 'pages');
    }

    private static function like(string $name): string
    {
        return '%' . strtr($name, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    private function query(string $sql, array $parameters): PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($parameters);
        return $query;
    }
}
