<?php
declare(strict_types=1);

namespace Dtrms\Models;

use DomainException;
use Dtrms\Services\ReportFilters;
use PDO;

final class ReportAnalytics
{
    private const LIMIT = 15000;

    public function __construct(private PDO $db)
    {
    }

    public function rows(string $scope, int $actor, ReportFilters $filters, ?int $reportId = null): array
    {
        $where = [$reportId === null ? 'r.report_date BETWEEN ? AND ?' : 'r.id = ?'];
        $args = $reportId === null ? [$filters->from, $filters->until] : [$reportId];
        $this->scope($scope, $actor, $where, $args);
        if ($reportId === null) {
            $this->dimensions($filters, ['department' => 'r.department_id', 'staff' => 'u.id', 'supervisor' => 'a.supervisor_id'], $where, $args);
        }

        $source = ' FROM daily_reports r JOIN users u ON u.id = r.staff_id JOIN departments d ON d.id = r.department_id
            LEFT JOIN supervisor_assignments a ON a.current_staff_id = u.id
            LEFT JOIN users supervisor ON supervisor.id = a.supervisor_id';
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $fields = $reportId === null
            ? 'r.id, r.report_date, r.reference_number, r.staff_id, r.department_id, r.status'
            : 'r.*';
        $rows = $this->query('SELECT ' . $fields . ', u.name AS staff_name, u.staff_id AS staff_code, d.name AS department_name,
            supervisor.name AS supervisor_name, a.supervisor_id, v.working_minutes AS recorded_minutes,
            JSON_UNQUOTE(JSON_EXTRACT(v.snapshot, \'$.arrival_time\')) AS recorded_arrival,
            JSON_UNQUOTE(JSON_EXTRACT(v.snapshot, \'$.overnight\')) AS recorded_overnight,
            EXISTS (SELECT 1 FROM report_approvals pa WHERE pa.report_id = r.id AND pa.stage = \'supervisor\'
                AND pa.decision = \'approved\' AND pa.revision_number = (
                    SELECT MAX(l.revision_number) FROM report_revisions l WHERE l.report_id = r.id
                )) AS supervised'
            . $source . ' LEFT JOIN report_revisions v ON v.report_id = r.id AND v.revision_number = (
                SELECT MAX(x.revision_number) FROM report_revisions x WHERE x.report_id = r.id AND x.submitted_at IS NOT NULL
            )' . $whereSql . ' ORDER BY r.report_date DESC, r.id DESC LIMIT ' . ($reportId === null ? self::LIMIT + 1 : 1), $args);
        if (count($rows) > self::LIMIT) {
            throw new DomainException('The selection exceeds 15,000 reports. Narrow the reporting period or filters.');
        }
        return $rows;
    }

    public function roster(string $scope, int $actor, ReportFilters $filters): array
    {
        $where = ['d.is_active', 'u.staff_id IS NOT NULL', "roles.code = 'staff'"];
        $args = [];
        if ($scope !== 'organization') {
            $where[] = $scope === 'staff' ? 'u.id = ?' : 'a.supervisor_id = ?';
            $args[] = $actor;
        }
        $this->dimensions($filters, ['department' => 'd.id', 'staff' => 'u.id', 'supervisor' => 'a.supervisor_id'], $where, $args);
        $rows = $this->query('SELECT u.id AS staff_id, u.name AS staff_name, u.staff_id AS staff_code,
            d.id AS department_id, d.name AS department_name, u.created_at, u.is_active
            FROM users u JOIN roles ON roles.id = u.role_id JOIN departments d ON d.id = u.department_id
            LEFT JOIN supervisor_assignments a ON a.current_staff_id = u.id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY d.name, u.name, u.id LIMIT ' . (self::LIMIT + 1), $args);
        if (count($rows) > self::LIMIT) {
            throw new DomainException('The roster exceeds 15,000 staff members. Narrow the department or supervisor filter.');
        }
        return $rows;
    }

    public function accountHistory(array $staffIds): array
    {
        $history = [];
        foreach (array_chunk($staffIds, 500) as $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->query("SELECT user_id,is_active,changed_at FROM user_account_status_history
                WHERE user_id IN ($marks) ORDER BY user_id,changed_at,id", $ids) as $row) {
                $history[$row['user_id']][] = $row;
            }
        }
        return $history;
    }

    public function weeklySchedules(): WeeklySchedules
    {
        return new WeeklySchedules($this->db);
    }

    public function monitoring(string $deadline): SubmissionMonitoring
    {
        return new SubmissionMonitoring($this->db,new StaffWorkSchedules($this->db),$deadline);
    }

    public function approvalGroups(array $reportIds): array
    {
        $totals = [];
        foreach (array_chunk($reportIds, 800) as $ids) {
            $rows = $this->query("SELECT a.stage, a.reviewer_id, u.name,
                COUNT(CASE WHEN a.decision = 'approved' THEN 1 END) AS approved,
                COUNT(CASE WHEN a.decision = 'returned' THEN 1 END) AS returned
                FROM report_approvals a JOIN users u ON u.id = a.reviewer_id
                WHERE a.report_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
                GROUP BY a.stage, a.reviewer_id, u.name', $ids);
            foreach ($rows as $row) {
                $key = $row['stage'] . ':' . $row['reviewer_id'];
                $totals[$key] ??= [$row['stage'], $row['name'], 0, 0];
                $totals[$key][2] += (int) $row['approved'];
                $totals[$key][3] += (int) $row['returned'];
            }
        }
        $totals = array_values($totals);
        usort($totals, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $totals;
    }

    public function options(string $scope, int $actor): array
    {
        if ($scope === 'staff') {
            $departments = $this->query('SELECT DISTINCT d.id, d.name FROM departments d
                WHERE EXISTS (SELECT 1 FROM daily_reports r WHERE r.department_id = d.id AND r.staff_id = ?)
                   OR EXISTS (SELECT 1 FROM users u WHERE u.department_id = d.id AND u.id = ?)
                ORDER BY d.name, d.id', [$actor, $actor]);
            return ['department' => $departments, 'staff' => [], 'supervisor' => []];
        }
        if ($scope === 'supervisor') {
            $departments = $this->query('SELECT DISTINCT d.id, d.name FROM departments d
                WHERE EXISTS (SELECT 1 FROM users u JOIN supervisor_assignments a ON a.current_staff_id = u.id
                    WHERE u.department_id = d.id AND a.supervisor_id = ?)
                   OR EXISTS (SELECT 1 FROM daily_reports r JOIN supervisor_assignments a ON a.current_staff_id = r.staff_id
                    WHERE r.department_id = d.id AND a.supervisor_id = ?) ORDER BY d.name, d.id', [$actor, $actor]);
            $staff = $this->query('SELECT u.id, u.name FROM users u JOIN supervisor_assignments a ON a.current_staff_id = u.id
                WHERE a.supervisor_id = ? ORDER BY u.name, u.id', [$actor]);
            return ['department' => $departments, 'staff' => $staff, 'supervisor' => []];
        }
        return [
            'department' => $this->query('SELECT id, name FROM departments ORDER BY name, id'),
            'staff' => $this->query("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id AND r.code = 'staff' ORDER BY u.name, u.id"),
            'supervisor' => $this->query("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id AND r.code = 'supervisor' ORDER BY u.name, u.id"),
        ];
    }

    private function scope(string $scope, int $actor, array &$where, array &$args): void
    {
        if ($scope === 'staff') {
            $where[] = 'r.staff_id = ?';
            $args[] = $actor;
        } elseif ($scope === 'supervisor') {
            $where[] = "a.supervisor_id = ? AND r.staff_id <> a.supervisor_id AND r.status <> 'draft'";
            $args[] = $actor;
        } elseif ($scope === 'organization') {
            $where[] = "r.status <> 'draft'";
        } else {
            throw new \LogicException('Unknown report scope.');
        }
    }

    private function dimensions(ReportFilters $filters, array $columns, array &$where, array &$args): void
    {
        foreach ($columns as $key => $column) {
            if ($filters->dimensions[$key] !== 0) {
                $where[] = $column . ' = ?';
                $args[] = $filters->dimensions[$key];
            }
        }
    }

    private function query(string $sql, array $args = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement->fetchAll();
    }
}
