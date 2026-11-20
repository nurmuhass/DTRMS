<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;
use PDOStatement;

final class HrReviews
{
    public const ROLES = ['hr_officer', 'hr_manager'];
    public const READ = 'hr_review_reports';
    public const DECIDE = 'hr_decide_reports';
    public const PENDING = ['pending_hr', 'supervisor_approved'];
    private const PENDING_SQL = "r.status IN ('pending_hr','supervisor_approved')";
    private const PROOF = " FROM daily_reports r
        JOIN report_approvals s ON s.report_id = r.id AND s.stage = 'supervisor' AND s.decision = 'approved'
            AND s.revision_number = (SELECT MAX(v.revision_number) FROM report_revisions v WHERE v.report_id = r.id)";
    private const AWAITING = " AND NOT EXISTS (
        SELECT 1 FROM report_approvals h WHERE h.report_id = r.id AND h.revision_number = s.revision_number AND h.stage = 'hr'
    )";
    private const RETURNED = "r.status IN ('returned','returned_by_supervisor','returned_by_hr')";
    private const VIEWABLE = "r.status IN ('pending_hr','supervisor_approved','hr_approved','returned_by_hr','returned')";
    private const HR_RETURNED = "r.status IN ('returned','returned_by_hr') AND EXISTS (
        SELECT 1 FROM report_approvals h WHERE h.report_id = r.id AND h.revision_number = s.revision_number
            AND h.stage = 'hr' AND h.decision = 'returned'
    )";

    public function __construct(private PDO $db)
    {
    }

    public function dashboard(): array
    {
        $departments = $this->query("SELECT d.name, d.code, COUNT(r.id) AS total,
            COUNT(CASE WHEN r.status <> 'draft' THEN 1 END) AS submitted,
            COUNT(CASE WHEN r.status = 'submitted' THEN 1 END) AS pending_supervisor,
            COUNT(CASE WHEN p.id IS NOT NULL THEN 1 END) AS pending_hr,
            COUNT(CASE WHEN r.status = 'hr_approved' THEN 1 END) AS approved,
            COUNT(CASE WHEN " . self::RETURNED . " THEN 1 END) AS returned
            FROM departments d
            LEFT JOIN daily_reports r ON r.department_id = d.id
            LEFT JOIN (
                SELECT r.id" . self::PROOF . ' WHERE ' . self::PENDING_SQL . self::AWAITING . "
            ) p ON p.id = r.id
            GROUP BY d.id, d.name, d.code ORDER BY submitted DESC, d.name, d.id")->fetchAll();
        $counts = array_fill_keys(['total', 'approved', 'returned', 'pending_supervisor', 'pending_hr'], 0);
        foreach ($departments as $department) {
            foreach ($counts as $field => $value) {
                $counts[$field] = $value + (int) $department[$field];
            }
        }
        return compact('counts', 'departments');
    }

    public function pendingCount(): int
    {
        return (int) $this->query('SELECT COUNT(*)' . self::PROOF
            . ' WHERE ' . self::PENDING_SQL . self::AWAITING)->fetchColumn();
    }

    public function listing(string $mode, string $name, string $date, int $page): array
    {
        $condition = match ($mode) {
            'approved' => "r.status = 'hr_approved'",
            'returned' => self::HR_RETURNED,
            default => self::PENDING_SQL . self::AWAITING,
        };
        $from = self::PROOF . ' JOIN users staff ON staff.id = r.staff_id JOIN users reviewer ON reviewer.id = s.reviewer_id';
        $where = ' WHERE ' . $condition;
        $args = [];
        if ($name !== '') {
            $needle = '%' . strtr($name, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (staff.name LIKE ? ESCAPE '!' OR r.reporter_name LIKE ? ESCAPE '!')";
            $args = [$needle, $needle];
        }
        if ($date !== '') {
            $where .= ' AND r.report_date = ?';
            $args[] = $date;
        }
        $count = (int) $this->query('SELECT COUNT(*)' . $from . $where, $args)->fetchColumn();
        $pages = max(1, (int) ceil($count / 20));
        $page = min($page, $pages);
        $rows = $this->query('SELECT r.id, r.reference_number, r.report_date, r.status, r.department_name_snapshot,
            COALESCE(r.reporter_name, staff.name) AS staff_name, reviewer.name AS supervisor_name,
            s.created_at AS supervisor_approved_at' . $from . $where
            . ' ORDER BY r.report_date DESC, r.id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), $args)->fetchAll();
        return compact('rows', 'count', 'page', 'pages');
    }

    public function find(int $report): ?array
    {
        return $this->query('SELECT r.*, staff.name AS staff_name, s.revision_number AS supervised_revision,
            reviewer.name AS supervisor_name, s.created_at AS supervisor_approved_at, s.comments AS supervisor_comments'
            . self::PROOF . " JOIN users staff ON staff.id = r.staff_id JOIN users reviewer ON reviewer.id = s.reviewer_id
            WHERE r.id = ? AND " . self::VIEWABLE,
            [$report])->fetch() ?: null;
    }

    public function viewableIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->query('SELECT r.id' . self::PROOF . ' WHERE ' . self::VIEWABLE
            . " AND r.id IN ($placeholders)", $ids)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function lock(int $staff, int $report): ?array
    {
        $this->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$staff])->fetchColumn();
        return $this->query('SELECT * FROM daily_reports WHERE id = ? AND staff_id = ? FOR UPDATE', [$report, $staff])->fetch() ?: null;
    }

    public function canDecide(int $actor): bool
    {
        return count($this->query("SELECT p.id FROM users u JOIN roles r ON r.id = u.role_id
            JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
            WHERE u.id = ? AND u.is_active AND u.must_change_password = FALSE AND r.code IN ('hr_officer','hr_manager')
                AND p.code IN (?, ?) LOCK IN SHARE MODE", [$actor, self::READ, self::DECIDE])->fetchAll()) === 2;
    }

    public function awaitingRevision(int $report): int
    {
        return (int) $this->query('SELECT s.revision_number' . self::PROOF
            . ' WHERE r.id = ? AND ' . self::PENDING_SQL . self::AWAITING, [$report])->fetchColumn();
    }

    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($parameters);
        return $query;
    }
}
