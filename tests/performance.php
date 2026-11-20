<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Models\HrReviews;
use Dtrms\Models\Notifications;
use Dtrms\Models\Organization;
use Dtrms\Models\ReportAnalytics;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\ReportFilters;
use Dtrms\Services\ReportingSummary;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$config = require dirname(__DIR__) . '/bootstrap/app.php';
if (!str_starts_with($config['database']['name'], 'dtrms_test_')
    || !in_array($config['database']['host'], ['127.0.0.1', '::1', 'localhost'], true)) {
    fwrite(STDERR, "Use a disposable, migrated and seeded loopback dtrms_test_* schema.\n");
    exit(2);
}

$db = Database::connect($config['database']);
$roles = $db->query('SELECT code, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
$today = new DateTimeImmutable('today');
$from = $today->modify('first day of this month')->modify('-3 months');
$until = $today->modify('last day of previous month');
$user = $db->prepare('INSERT INTO users (role_id, department_id, staff_id, name, email, password_hash, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)');
$dept = $db->prepare('INSERT INTO departments (code, name) VALUES (?, ?)');
$assignment = $db->prepare('INSERT INTO supervisor_assignments (staff_id, supervisor_id, assigned_by, started_at) VALUES (?, ?, ?, ?)');
$schedule = $db->prepare('INSERT INTO staff_work_schedules (user_id, assignment_key, day_of_week, effective_from, created_by)
    VALUES (?, ?, ?, ?, ?)');
$leave = $db->prepare("INSERT INTO staff_leaves (user_id,leave_type,start_date,end_date,reason,status,approved_by,approved_at,created_by)
    VALUES (?,'annual',?,?,'Synthetic leave','approved',?,CURRENT_TIMESTAMP,?)");
$holiday = $db->prepare("INSERT INTO work_holidays (holiday_date,name,department_id,created_by)
    VALUES (?,'Synthetic non-working holiday',?,?)");
$report = $db->prepare("INSERT INTO daily_reports
    (staff_id, department_id, report_date, content, status, reference_number, reporter_name, reporter_staff_code,
    department_name_snapshot, arrival_time, work_start_time, work_end_time, declared, working_minutes, first_submitted_at, submitted_at)
    VALUES (?, ?, ?, 'Synthetic workload', ?, ?, ?, ?, ?, ?, '08:35', '17:05', 1, 510, ?, ?)");
$revision = $db->prepare("INSERT INTO report_revisions (report_id,revision_number,revised_by,content,snapshot,submitted_at,working_minutes)
    VALUES (?,1,?,'Synthetic workload',JSON_OBJECT('arrival_time',?,'overnight',false),?,510)");
$decision = $db->prepare("INSERT INTO report_approvals (report_id,revision_number,reviewer_id,stage,decision,comments,created_at)
    VALUES (?,1,?,?,?,'Synthetic decision',?)");
$transition = $db->prepare('UPDATE daily_reports SET status = ? WHERE id = ?');
$notification = $db->prepare("INSERT INTO notifications (user_id,report_id,type,message,created_at)
    VALUES (?,?,'report_submitted','Synthetic notification',?)");
$run = bin2hex(random_bytes(4));
$created = $from->modify('-7 days')->format('Y-m-d 12:00:00');
$hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

[$supervisor, $reportCount, $decisionCount] = Database::transaction($db, function () use (
    $db, $roles, $user, $dept, $assignment, $report, $revision, $decision, $transition, $notification,
    $run, $hash, $created, $from, $until, $schedule, $leave, $holiday
): array {
    $user->execute([$roles['supervisor'], null, "S-$run", 'Synthetic Supervisor', "supervisor-$run@example.test", $hash, $created]);
    $supervisor = (int) $db->lastInsertId();
    $user->execute([$roles['hr_manager'], null, "H-$run", 'Synthetic HR reviewer', "reviewer-$run@example.test", $hash, $created]);
    $reviewer = (int) $db->lastInsertId();
    $reportCount = $decisionCount = 0;
    for ($i = 0; $i < 70; $i++) {
        if ($i % 10 === 0) {
            $departmentName = "Synthetic $run department " . intdiv($i, 10);
            $dept->execute(["P$run" . intdiv($i, 10), $departmentName]);
            $department = (int) $db->lastInsertId();
            $holiday->execute([$from->modify('+14 days')->format('Y-m-d'), $department, $reviewer]);
        }
        $staffCode = "P-$run-$i";
        $name = "Synthetic $run staff $i";
        $user->execute([$roles['staff'], $department, $staffCode, $name, "$staffCode@example.test", $hash, $created]);
        $staff = (int) $db->lastInsertId();
        $assignment->execute([$staff, $supervisor, $reviewer, $created]);
        $assignmentKey = bin2hex(random_bytes(16));
        foreach ([[1,3,5], [2,4,6], [1,2,4]][$i % 3] as $workday) {
            $schedule->execute([$staff, $assignmentKey, $workday, $from->format('Y-m-d'), $reviewer]);
        }
        if ($i % 4 === 0) {
            $leaveStart = $from->modify('+' . (2 + ($i % 28)) . ' days');
            $leave->execute([$staff, $leaveStart->format('Y-m-d'), $leaveStart->modify('+4 days')->format('Y-m-d'), $reviewer, $reviewer]);
        }
        for ($day = $from; $day <= $until; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') > 5 || ($i + (int) $day->format('z')) % 13 === 0) {
                continue;
            }
            $reportCount++;
            $date = $day->format('Y-m-d');
            $stamp = $date . ' 16:30:00';
            $arrival = $reportCount % 4 === 0 ? '08:25:00' : '08:05:00';
            $step = $reportCount % 10;
            $report->execute([$staff, $department, $date, 'submitted', 'PERF-' . $run . '-' . $reportCount,
                $name, $staffCode, $departmentName, $arrival, $stamp, $stamp]);
            $id = (int) $db->lastInsertId();
            $revision->execute([$id, $staff, $arrival, $stamp]);
            $notification->execute([$supervisor, $id, $stamp]);
            if ($step <= 1) {
                continue;
            }
            $decision->execute([$id, $supervisor, 'supervisor', $step === 2 ? 'returned' : 'approved', $stamp]);
            $decisionCount++;
            $transition->execute([$step === 2 ? 'returned_by_supervisor' : 'pending_hr', $id]);
            if ($step >= 5) {
                $decision->execute([$id, $reviewer, 'hr', $step === 5 ? 'returned' : 'approved', $stamp]);
                $decisionCount++;
                $transition->execute([$step === 5 ? 'returned_by_hr' : 'hr_approved', $id]);
            }
        }
    }
    return [$supervisor, $reportCount, $decisionCount];
});

$filters = ReportFilters::parse(['from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d'),
    'supervisor' => (string) $supervisor], $today);
$summary = new ReportingSummary(new ReportAnalytics($db), OperationalPolicy::DEFAULTS,
    new \Dtrms\Models\StaffWorkSchedules($db), new \Dtrms\Models\StaffLeaves($db), new \Dtrms\Models\WorkHolidays($db));
$hr = new HrReviews($db);
$organization = new Organization($db);
$notifications = new Notifications($db);
$compliance = new \Dtrms\Models\SubmissionMonitoring($db, new \Dtrms\Models\StaffWorkSchedules($db),
    OperationalPolicy::DEFAULTS['report_deadline']);
$results = [];
$cases = [
    'monthly_submission_monitoring_page_2' => function () use ($compliance, $from, $today, $run): bool {
        $result = $compliance->dashboard(['q' => "Synthetic $run staff", 'page' => 2], $from,
            $from->modify('last day of this month'), $today);
        return $result['count'] === 70 && count($result['rows']) === 20 && (int) $result['summary']['actual_total'] > 500;
    },
    'three_month_submission_monitoring' => function () use ($compliance, $from, $until, $today, $run, $reportCount): bool {
        $result = $compliance->dashboard(['q' => "Synthetic $run staff"], $from, $until, $today);
        return $result['count'] === 70 && (int) $result['summary']['actual_total'] === $reportCount;
    },
    'three_month_analytics' => function () use ($summary, $filters, $today, $reportCount): bool {
        $result = $summary->build('organization', 0, $filters, $today);
        return $result['stats']['submitted'] === $reportCount && count($result['people']) === 70
            && count($result['departments']) === 21 && $result['stats']['late'] > 0
            && array_sum(array_column($result['departments'], 'missing')) > 0;
    },
    'hr_dashboard' => function () use ($hr, $reportCount): bool {
        $counts = $hr->dashboard()['counts'];
        return $counts['total'] >= $reportCount && $counts['pending_hr'] > 0 && $counts['approved'] > 0;
    },
    'hr_queue_name_page_2' => function () use ($hr, $run): bool {
        $page = $hr->listing('pending', "Synthetic $run staff", '', 2);
        return count($page['rows']) === 20;
    },
    'staff_directory_search_page_3' => function () use ($organization, $run): bool {
        $page = $organization->directory("Synthetic $run staff", 'active', 'staff', 0, 3, true);
        return $page['count'] === 70 && count($page['rows']) === 20;
    },
    'notification_history_page_20' => function () use ($notifications, $supervisor, $reportCount): bool {
        $page = $notifications->inbox($supervisor, 20);
        return $page['count'] === $reportCount && count($page['rows']) === 20 && $notifications->unread($supervisor) === $reportCount;
    },
];
foreach ($cases as $label => $case) {
    $samples = [];
    for ($i = 0; $i < 21; $i++) {
        $start = hrtime(true);
        if (!$case()) {
            fwrite(STDERR, "Workload assertion failed: $label\n");
            exit(1);
        }
        if ($i > 0) {
            $samples[] = (hrtime(true) - $start) / 1e6;
        }
    }
    sort($samples);
    $results[$label] = ['median_ms' => round(($samples[9] + $samples[10]) / 2, 2), 'p95_ms' => round($samples[18], 2)];
}

echo json_encode(['mysql' => $db->query('SELECT VERSION()')->fetchColumn(), 'php' => PHP_VERSION,
    'staff' => 70, 'departments' => 7, 'months' => 3, 'reports' => $reportCount, 'decisions' => $decisionCount,
    'notifications' => $reportCount, 'samples_per_case' => 20, 'measurements' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
