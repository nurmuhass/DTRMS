<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;
use PDOStatement;

final class StaffReports
{
    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Pending supervisor approval',
        'supervisor_approved' => 'Pending HR approval',
        'pending_hr' => 'Pending HR approval',
        'hr_approved' => 'HR approved',
        'returned' => 'Returned',
        'returned_by_supervisor' => 'Returned by supervisor',
        'returned_by_hr' => 'Returned by HR',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function profile(int $staff): ?array
    {
        return $this->query(
            "SELECT u.name, u.staff_id AS staff_code, u.department_id, u.future_reports_through,
                d.name AS department, d.is_active AS department_active,
                schedule.earliest_arrival, schedule.latest_end
             FROM users u JOIN roles r ON r.id = u.role_id AND r.code = 'staff'
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN department_overnight_schedules schedule ON schedule.department_id = d.id
             WHERE u.id = ? AND u.is_active",
            [$staff]
        )->fetch() ?: null;
    }

    public function lockProfile(int $staff): ?array
    {
        $eligible = $this->query("SELECT u.id FROM users u WHERE u.id = ? AND u.is_active AND u.must_change_password = FALSE
            AND EXISTS (
                SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id = u.role_id AND p.code = 'staff_workspace'
            ) FOR UPDATE", [$staff])->fetchColumn();
        return $eligible ? $this->profile($staff) : null;
    }

    public function find(int $staff, int $id, bool $lock = false): ?array
    {
        return $this->query(
            'SELECT * FROM daily_reports WHERE staff_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$staff, $id]
        )->fetch() ?: null;
    }

    public function counts(int $staff): array
    {
        return $this->query("SELECT COUNT(*) AS total,
            COUNT(CASE WHEN status = 'submitted' THEN 1 END) AS pending_supervisor,
            COUNT(CASE WHEN status IN ('supervisor_approved','pending_hr') THEN 1 END) AS pending_hr,
            COUNT(CASE WHEN status = 'hr_approved' THEN 1 END) AS approved,
            COUNT(CASE WHEN status IN ('returned','returned_by_supervisor','returned_by_hr') THEN 1 END) AS returned
            FROM daily_reports WHERE staff_id = ?", [$staff])->fetch();
    }

    public function recent(int $staff): array
    {
        return $this->query('SELECT id, reference_number, report_date, status, updated_at
            FROM daily_reports WHERE staff_id = ? ORDER BY updated_at DESC, id DESC LIMIT 5', [$staff])->fetchAll();
    }

    public function listing(int $staff, string $date, string $status, int $page): array
    {
        $where = ' WHERE staff_id = ?';
        $parameters = [$staff];
        if ($date !== '') {
            $where .= ' AND report_date = ?';
            $parameters[] = $date;
        }
        if (in_array($status, ['pending_hr', 'supervisor_approved'], true)) {
            $where .= " AND status IN ('supervisor_approved','pending_hr')";
        } elseif ($status === 'returned') {
            $where .= " AND status IN ('returned','returned_by_supervisor','returned_by_hr')";
        } elseif ($status !== '') {
            $where .= ' AND status = ?';
            $parameters[] = $status;
        }
        $count = (int) $this->query('SELECT COUNT(*) FROM daily_reports' . $where, $parameters)->fetchColumn();
        $pages = max(1, (int) ceil($count / 20));
        $page = min($page, $pages);
        $offset = ($page - 1) * 20;
        $rows = $this->query('SELECT id, reference_number, report_date, status, submitted_at, updated_at
            FROM daily_reports' . $where . ' ORDER BY report_date DESC, id DESC LIMIT 20 OFFSET ' . $offset, $parameters)->fetchAll();

        return compact('rows', 'page', 'pages', 'count');
    }

    public function attendance(int $staff, string $from, string $until): array
    {
        return $this->query('SELECT COUNT(*) AS days, COALESCE(SUM(v.working_minutes), 0) AS minutes
            FROM daily_reports r
            JOIN report_revisions v ON v.report_id = r.id AND v.revision_number = (
                SELECT MAX(s.revision_number) FROM report_revisions s
                WHERE s.report_id = r.id AND s.submitted_at IS NOT NULL
            )
            WHERE r.staff_id = ? AND r.report_date >= ? AND r.report_date < ? AND v.working_minutes IS NOT NULL',
            [$staff, $from, $until])->fetch();
    }

    public function approvals(int $staff, int $report): array
    {
        return $this->query('SELECT a.revision_number, a.stage, a.decision, a.comments, a.created_at, reviewer.name
            FROM report_approvals a JOIN daily_reports r ON r.id = a.report_id JOIN users reviewer ON reviewer.id = a.reviewer_id
            WHERE r.staff_id = ? AND r.id = ? ORDER BY a.revision_number DESC, a.created_at DESC, a.id DESC',
            [$staff, $report])->fetchAll();
    }

    public function revisions(int $staff, int $report): array
    {
        $rows = $this->query('SELECT v.revision_number, v.content, v.snapshot, v.submitted_at
            FROM report_revisions v JOIN daily_reports r ON r.id = v.report_id
            WHERE r.staff_id = ? AND r.id = ? ORDER BY v.revision_number DESC', [$staff, $report])->fetchAll();
        foreach ($rows as &$row) {
            $row['fields'] = $row['snapshot'] === null ? ['content' => $row['content']]
                : json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $rows;
    }

    public function hasSupervisor(int $staff): bool
    {
        return (bool) $this->query("SELECT 1 FROM supervisor_assignments a
            JOIN users s ON s.id = a.supervisor_id AND s.is_active
            JOIN roles r ON r.id = s.role_id AND r.code = 'supervisor' WHERE a.current_staff_id = ?", [$staff])->fetchColumn();
    }

    public function writeReport(int $staff, ?array $existing, array $record, ?string $submittedAt): int
    {
        $columns = [
            'department_id', 'report_date', 'content', 'arrival_time', 'work_start_time', 'work_end_time',
            'achievement', 'challenges', 'declared', 'overnight', 'working_minutes',
            'reporter_name', 'reporter_staff_code', 'department_name_snapshot',
        ];
        $values = array_map(static fn (string $column): mixed => $record[$column], $columns);
        $status = $submittedAt !== null ? 'submitted' : ($existing['status'] ?? 'draft');

        if ($existing === null) {
            $columns = array_merge($columns, ['staff_id', 'reference_number', 'status', 'submitted_at', 'first_submitted_at', 'lock_version']);
            $values = array_merge($values, [$staff, 'DTR-' . strtoupper(bin2hex(random_bytes(12))), $status, $submittedAt, $submittedAt, 1]);
            $this->query('INSERT INTO daily_reports (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($values), '?')) . ')', $values);
            return (int) $this->db->lastInsertId();
        }

        $id = (int) $existing['id'];
        $sets = implode(' = ?, ', $columns) . ' = ?';
        $this->query('UPDATE daily_reports SET ' . $sets . ', status = ?, submitted_at = COALESCE(?, submitted_at),
            first_submitted_at = COALESCE(first_submitted_at, ?), lock_version = lock_version + 1 WHERE staff_id = ? AND id = ?',
            array_merge($values, [$status, $submittedAt, $submittedAt, $staff, $id]));

        return $id;
    }

    public function latestRevision(int $report): int
    {
        return (int) $this->query('SELECT COALESCE(MAX(revision_number), 0) FROM report_revisions WHERE report_id = ?',
            [$report])->fetchColumn();
    }

    public function snapshot(int $report, int $revision, int $staff, array $record, ?string $submittedAt): void
    {
        $snapshot = array_intersect_key($record, array_flip([
            'department_id', 'report_date', 'content', 'arrival_time', 'work_start_time', 'work_end_time',
            'achievement', 'challenges', 'declared', 'overnight', 'working_minutes',
            'reporter_name', 'reporter_staff_code', 'department_name_snapshot',
        ]));
        $this->query('INSERT INTO report_revisions (report_id, revision_number, revised_by, content, snapshot, submitted_at, working_minutes)
            VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $report, $revision, $staff, $record['content'], json_encode($snapshot, JSON_THROW_ON_ERROR), $submittedAt,
            $record['working_minutes'],
        ]);
    }

    private function query(string $sql, array $args): PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($args);
        return $query;
    }
}
