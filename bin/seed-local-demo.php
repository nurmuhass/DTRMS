<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Models\UserAccount;
use Dtrms\Models\WeeklySchedules;
use Dtrms\Services\PasswordPolicy;
use Dtrms\Services\WeeklyCompliance;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$connection = $config['database'];
if ($connection['host'] !== '127.0.0.1'
    || ($connection['name'] !== 'dtrms_local' && !str_starts_with($connection['name'], 'dtrms_test_'))
    || ($connection['name'] === 'dtrms_local' && (string) $connection['port'] !== '33742')) {
    fwrite(STDERR, "Use the local DTRMS database on port 33742 or a disposable dtrms_test_* database.\n");
    exit(2);
}

$db = Database::connect($connection);
$now = new DateTimeImmutable();
$week = WeeklyCompliance::week($now);
$first = $week->modify('-5 weeks');
$next = $week->modify('+7 days');
$cutover = (new WeeklySchedules($db))->start();
if ($cutover === '') {
    fwrite(STDERR, "Apply the DTRMS migrations before loading demo data.\n");
    exit(2);
}
$roles = $db->query('SELECT code, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
$hash = PasswordPolicy::hash(bin2hex(random_bytes(32)));
$counts = Database::transaction($db, static function () use ($db, $now, $week, $first, $next, $cutover, $roles, $hash): ?array {
    if ($db->query("SELECT id FROM departments WHERE code='DEMO-OPS'")->fetchColumn()) {
        return null;
    }
    $department = $db->prepare('INSERT INTO departments(code,name) VALUES(?,?)');
    $user = $db->prepare('INSERT INTO users(role_id,department_id,staff_id,name,email,password_hash,created_at)
        VALUES(?,?,?,?,?,?,?)');
    $created = WeeklyCompliance::utc($first->modify('-1 week'));
    $createUser = static function (string $role, ?int $dept, string $code, string $name) use ($db, $user, $roles, $hash, $created): int {
        $user->execute([$roles[$role], $dept, $code, $name, strtolower($code) . '@example.test', $hash, $created]);
        return (int) $db->lastInsertId();
    };
    $reviewer = $createUser('hr_manager', null, 'DEMO-HR', 'Demo HR Reviewer');
    $assignment = $db->prepare('INSERT INTO supervisor_assignments(staff_id,supervisor_id,assigned_by,started_at) VALUES(?,?,?,?)');
    $legacy = $db->prepare('INSERT INTO staff_work_schedules(user_id,assignment_key,day_of_week,effective_from,created_by) VALUES(?,?,?,?,?)');
    $schedule = $db->prepare('INSERT INTO staff_weekly_schedules(user_id,week_start,week_end,status,submitted_at) VALUES(?,?,?,?,?)');
    $selected = $db->prepare('INSERT INTO staff_weekly_schedule_days(weekly_schedule_id,scheduled_date) VALUES(?,?)');
    $leave = $db->prepare("INSERT INTO staff_leaves(user_id,leave_type,start_date,end_date,reason,status,approved_by,approved_at,created_by)
        VALUES(?,'annual',?,?,'Demo planned absence',?,?,?,?)");
    $report = $db->prepare("INSERT INTO daily_reports(staff_id,department_id,report_date,content,achievement,challenges,status,
        reference_number,reporter_name,reporter_staff_code,department_name_snapshot,arrival_time,work_start_time,work_end_time,
        declared,working_minutes,first_submitted_at,submitted_at)
        VALUES(?,?,?,'Prepared the daily work register and reviewed open requests.','Closed assigned follow-up items.','No issues raised.',?,?,?,?,?,?,
        '08:30','16:30',1,480,?,?)");
    $revision = $db->prepare("INSERT INTO report_revisions(report_id,revision_number,revised_by,content,snapshot,submitted_at,working_minutes)
        SELECT id,1,staff_id,content,JSON_OBJECT('content',content,'achievement',achievement,'challenges',challenges,
        'arrival_time',arrival_time,'work_start_time',work_start_time,'work_end_time',work_end_time,'working_minutes',working_minutes),submitted_at,working_minutes
        FROM daily_reports WHERE id=?");
    $decision = $db->prepare("INSERT INTO report_approvals(report_id,revision_number,reviewer_id,stage,decision,comments,created_at)
        VALUES(?,1,?,?,?,'Demo review decision.',?)");
    $transition = $db->prepare('UPDATE daily_reports SET status=? WHERE id=?');
    $counts = ['departments' => 0, 'supervisors' => 0, 'staff' => 0, 'reports' => 0, 'leave' => 0, 'weekly_schedules' => 0];
    foreach (['OPS' => 'Operations', 'FIN' => 'Finance', 'ICT' => 'ICT', 'ADM' => 'Administration'] as $code => $label) {
        $deptName = "Demo $label";
        $department->execute(["DEMO-$code", $deptName]);
        $dept = (int) $db->lastInsertId();
        $counts['departments']++;
        $supervisor = $createUser('supervisor', $dept, "DEMO-$code-S", "$deptName Supervisor");
        $counts['supervisors']++;
        for ($index = 1; $index <= 7; $index++) {
            $staffCode = "DEMO-$code-$index";
            $name = "$deptName Staff $index";
            $staff = $createUser('staff', $dept, $staffCode, $name);
            $counts['staff']++;
            $assignment->execute([$staff, $supervisor, $reviewer, $created]);
            $offsets = [[0, 2, 4], [1, 3, 5], [0, 1, 3]][$index % 3];
            $key = bin2hex(random_bytes(16));
            foreach ($offsets as $offset) {
                $legacy->execute([$staff, $key, $offset + 1, $first->format('Y-m-d'), $reviewer]);
            }

            $absenceStatus = [2 => 'approved', 4 => 'pending', 6 => 'rejected'][$index] ?? null;
            $absence = $week->modify("+{$offsets[0]} days")->format('Y-m-d');
            if ($absenceStatus !== null) {
                $approved = $absenceStatus === 'approved';
                $leave->execute([$staff, $absence, $absence, $absenceStatus, $approved ? $reviewer : null,
                    $approved ? WeeklyCompliance::utc($now) : null, $reviewer]);
                $counts['leave']++;
            }

            for ($start = $first; $start <= $next; $start = $start->modify('+7 days')) {
                $startDate = $start->format('Y-m-d');
                if ($startDate >= $cutover) {
                    if ($start == $next && $index === 7) {
                        continue;
                    }
                    $schedule->execute([$staff, $startDate, $start->modify('+6 days')->format('Y-m-d'),
                        $start <= $week ? 'locked' : 'submitted', WeeklyCompliance::utc(min($now, $start->modify('-1 day')))]);
                    $scheduleId = (int) $db->lastInsertId();
                    foreach ($offsets as $offset) {
                        $selected->execute([$scheduleId, $start->modify("+$offset days")->format('Y-m-d')]);
                    }
                    $counts['weekly_schedules']++;
                }
                foreach ($offsets as $offset) {
                    $day = $start->modify("+$offset days");
                    $date = $day->format('Y-m-d');
                    $variation = ($index + (int) $day->format('z')) % 10;
                    if ($date >= $now->format('Y-m-d') || $variation === 0 || ($absenceStatus === 'approved' && $date === $absence)) {
                        continue;
                    }
                    $draft = $variation === 1;
                    $stamp = WeeklyCompliance::utc($day->setTime(17, 0));
                    $counts['reports']++;
                    $ref = 'DEMO-R-' . $counts['reports'];
                    $report->execute([$staff, $dept, $date, $draft ? 'draft' : 'submitted', $ref, $name, $staffCode,
                        $deptName, $variation % 3 === 0 ? '08:20' : '07:55', $draft ? null : $stamp, $draft ? null : $stamp]);
                    $reportId = (int) $db->lastInsertId();
                    if ($draft) {
                        continue;
                    }
                    $revision->execute([$reportId]);
                    if ($variation <= 3) {
                        continue;
                    }
                    $returned = $variation === 4;
                    $decision->execute([$reportId, $supervisor, 'supervisor', $returned ? 'returned' : 'approved', $stamp]);
                    $transition->execute([$returned ? 'returned_by_supervisor' : 'pending_hr', $reportId]);
                    if ($variation >= 7) {
                        $returned = $variation === 7;
                        $decision->execute([$reportId, $reviewer, 'hr', $returned ? 'returned' : 'approved', $stamp]);
                        $transition->execute([$returned ? 'returned_by_hr' : 'hr_approved', $reportId]);
                    }
                }
            }
        }
    }
    (new UserAccount($db))->audit('local_demo_data_loaded', null, null, 'demo_data', null, $counts);
    return $counts;
});

echo $counts === null ? "Demo data is already present. No changes made.\n"
    : json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;




