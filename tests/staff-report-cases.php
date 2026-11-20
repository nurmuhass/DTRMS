<?php
declare(strict_types=1);

// Loaded by authentication.php after the HR and authentication fixtures are available.
use Dtrms\Services\PasswordPolicy;

$fixture = $db->prepare("INSERT INTO users (role_id, department_id, staff_id, name, email, password_hash, must_change_password)
    SELECT id, ?, ?, ?, ?, ?, FALSE FROM roles WHERE code = 'staff'");
$hash = PasswordPolicy::hash($newPassword);
$fixture->execute([$deptId, 'PORTAL-1', 'Portal Test Staff', 'portal-1@example.test', $hash]);
$owner = (int) $db->lastInsertId();
$fixture->execute([$deptId, 'PORTAL-2', 'Second Portal Staff', 'portal-2@example.test', $hash]);
$otherId = (int) $db->lastInsertId();

$portal = new TestBrowser($argv[1]);
$otherPortal = new TestBrowser($argv[1]);
$loginWith($portal, 'portal-1@example.test', $newPassword, 303, '/');
$loginWith($otherPortal, 'portal-2@example.test', $newPassword, 303, '/');
$editor = '/staff/reports/edit';
$history = '/staff/reports';
$show = '/staff/reports/view';

$dashboard = $portal->request('/staff', 200);
expect($dashboard->contains('Recent report activity') && $dashboard->contains('Staff-entered attendance'), 'staff dashboard sections');
$officerBrowser->request($history, 403);
$officerBrowser->request($editor, 403, ['csrf' => $officerBrowser->request('/', 200)->csrf()]);

$today = new DateTimeImmutable('today');
$date = $today->format('Y-m-d');
$tomorrow = $today->modify('+1 day')->format('Y-m-d');
$yesterday = $today->modify('-1 day')->format('Y-m-d');
$form = $portal->request($editor, 200);
expect($form->contains('Portal Test Staff') && $form->contains('PORTAL-1') && $form->contains('HR test department')
    && $form->contains('value="' . $date . '"'), 'authenticated report identity and default date');
$staffCsrf = $form->csrf();
$sendReport = static fn (string $path, int $status, array $fields): TestBrowser =>
    $portal->request($path, $status, ['csrf' => $staffCsrf] + $fields);
$complete = [
    'report_date' => $date, 'arrival_time' => '08:00', 'work_start_time' => '08:30', 'work_end_time' => '16:00',
    'content' => 'Original daily work', 'achievement' => 'Delivered the work', 'challenges' => 'None',
    'declared' => '1', 'action' => 'submit',
];

$portal->request($editor, 403, $complete);
expect($sendReport($editor, 422, array_replace($complete, ['report_date' => $tomorrow]))->contains('authorized date limit'), 'future reports blocked');
expect($sendReport($editor, 422, array_replace($complete, ['report_date' => '2026-02-30']))->contains('valid reporting date'), 'invalid calendar date rejected');
expect($sendReport($editor, 422, array_replace($complete, ['declared' => '']))->contains('Confirm the declaration'), 'declaration required for submission');
expect($sendReport($editor, 422, array_replace($complete, ['work_end_time' => '08:30']))->contains('Time ended must be later'), 'equal work times rejected');
expect($sendReport($editor, 422, $complete)->contains('assign an active supervisor'), 'missing supervisor blocks completed report');

$draftText = "Unfinished draft <strong>QA text</strong> & O'Brien";
$sendReport($editor, 303, ['report_date' => $date, 'content' => $draftText, 'action' => 'draft',
    'department_id' => $otherDeptId, 'staff_id' => $otherId, 'reporter_name' => 'Forged identity']);
$row = $db->query("SELECT * FROM daily_reports WHERE staff_id = $owner")->fetch();
$reportId = (int) $row['id'];
$location = $show . '?id=' . $reportId;
$editLocation = $editor . '?id=' . $reportId;
expect($row['status'] === 'draft' && (int) $row['department_id'] === $deptId && $row['reporter_name'] === 'Portal Test Staff'
    && $row['reporter_staff_code'] === 'PORTAL-1', 'server ignores forged account fields');
expect(preg_match('/^DTR-[A-F0-9]{24}$/', $row['reference_number']) === 1, 'new report has a reference number');
$draftView = $portal->request($location, 200)->body();
expect(str_contains($draftView, '&lt;strong&gt;QA text&lt;/strong&gt;') && !str_contains($draftView, '<strong>QA text</strong>'),
    'staff-authored markup is rendered as text');
expect($sendReport($editor, 422, ['report_date' => $date, 'action' => 'draft'])->contains('already exists'), 'one report per date');

$otherPortal->request($location, 404);
$otherPortal->request($editLocation, 404);
$otherPortal->request($editLocation, 404, ['csrf' => $otherPortal->request($editor, 200)->csrf()] + $complete);
expect(!$otherPortal->request($history, 200)->contains($row['reference_number']), 'another staff member cannot list the report');

$sendReport($editLocation, 303, ['report_date' => $date, 'content' => 'Second draft', 'action' => 'draft', 'lock_version' => '1']);
expect($sendReport($editLocation, 422, $complete + ['lock_version' => '1'])->contains('changed since you opened'), 'stale editor cannot overwrite changes');
$post($assignment . '?id=' . $owner, 303, ['supervisor_id' => $replacement, 'reason' => 'Portal fixture']);
$sendReport($editLocation, 303, $complete + ['lock_version' => '2']);
$submitted = $db->query("SELECT * FROM daily_reports WHERE id = $reportId")->fetch();
expect($submitted['status'] === 'submitted' && (int) $submitted['working_minutes'] === 450
    && $submitted['first_submitted_at'] !== null && $submitted['submitted_at'] === $submitted['first_submitted_at'],
    'submit records time, duration and pending status');
expect($portal->request('/staff', 200)->contains('7 h 30 min'), 'staff-entered attendance summarizes the submission');
$portal->request($editLocation, 409);
$sendReport($editLocation, 409, $complete + ['lock_version' => '3']);

$initialReview = $db->prepare('INSERT INTO report_approvals (report_id, revision_number, reviewer_id, stage, decision, comments)
    VALUES (?, ?, ?, ?, ?, ?)');
$hrReviewer = (int) $db->query("SELECT id FROM users WHERE email = 'hr_officer@example.test'")->fetchColumn();
$initialReview->execute([$reportId, 1, $replacement, 'supervisor', 'approved', 'Supervisor comment retained']);
$db->exec("UPDATE daily_reports SET status = 'supervisor_approved' WHERE id = $reportId");
expect($portal->request($history . '?status=supervisor_approved&date=' . $date, 200)->contains($row['reference_number']), 'HR pending status filter');
$initialReview->execute([$reportId, 1, $hrReviewer, 'hr', 'returned', 'HR requests a clearer achievement']);
$db->exec("UPDATE daily_reports SET status = 'returned' WHERE id = $reportId");
expect($portal->request($location, 200)->contains('Supervisor comment retained'), 'supervisor comment visible');
expect($portal->request($editLocation, 200)->contains('HR requests a clearer achievement'), 'returned review feedback visible');
$sendReport($editLocation, 422, array_replace($complete, ['report_date' => $yesterday]) + ['lock_version' => '3']);
$corrections = array_replace($complete, ['content' => 'Corrected daily work', 'work_end_time' => '17:00']);
$sendReport($editLocation, 303, array_replace($corrections, ['action' => 'draft']) + ['lock_version' => '3']);
expect($portal->request('/staff', 200)->contains('7 h 30 min'), 'unsubmitted corrections do not change attendance');
$sendReport($editLocation, 303, $corrections + ['lock_version' => '4']);

$revised = $db->query("SELECT status, first_submitted_at, submitted_at, reference_number FROM daily_reports WHERE id = $reportId")->fetch();
expect($revised['status'] === 'submitted' && $revised['first_submitted_at'] === $submitted['first_submitted_at']
    && $revised['submitted_at'] >= $submitted['submitted_at'] && $revised['reference_number'] === $row['reference_number'],
    'resubmission preserves first timestamp and report reference');
$snapshots = $db->query("SELECT content, working_minutes, submitted_at, snapshot FROM report_revisions WHERE report_id = $reportId ORDER BY revision_number")->fetchAll();
expect(count($snapshots) === 2 && $snapshots[0]['content'] === 'Original daily work' && $snapshots[1]['content'] === 'Corrected daily work'
    && (int) $snapshots[0]['working_minutes'] === 450 && (int) $snapshots[1]['working_minutes'] === 510
    && json_decode($snapshots[0]['snapshot'], true)['reporter_staff_code'] === 'PORTAL-1', 'structured submission snapshots preserve both versions');
expect($portal->request($location, 200)->contains('HR requests a clearer achievement') && $portal->contains('Original daily work'), 'prior decisions and submission stay visible');
$initialReview->execute([$reportId, 2, $replacement, 'supervisor', 'approved', 'Corrected report accepted']);
$db->exec("UPDATE daily_reports SET status = 'pending_hr' WHERE id = $reportId");
$initialReview->execute([$reportId, 2, $hrReviewer, 'hr', 'approved', 'HR approval recorded']);
$db->exec("UPDATE daily_reports SET status = 'hr_approved' WHERE id = $reportId");
expect($portal->request($history . '?status=hr_approved&date=' . $date, 200)->contains($row['reference_number']), 'date and HR-approved filters');
expect(!$portal->request($history . '?status=returned&date=' . $date, 200)->contains($row['reference_number']), 'status filter excludes other reports');
$portal->request($editLocation, 409);

$night = array_replace($complete, ['report_date' => $yesterday, 'arrival_time' => '22:15', 'work_start_time' => '00:30', 'work_end_time' => '05:15']);
expect($sendReport($editor, 422, $night)->contains('matching department work schedule'), 'overnight shift denied by default');
$db->exec("INSERT INTO department_overnight_schedules (department_id, earliest_arrival, latest_end) VALUES ($deptId, '18:00', '07:00')");
expect($sendReport($editor, 422, array_replace($night, ['work_end_time' => '09:00']))->contains('matching department work schedule'), 'overnight window enforced');
$sendReport($editor, 303, $night);
$nightRow = $db->query("SELECT overnight, working_minutes, reference_number FROM daily_reports WHERE staff_id = $owner AND report_date <> '$date'")->fetch();
expect((bool) $nightRow['overnight'] && (int) $nightRow['working_minutes'] === 285
    && $nightRow['reference_number'] !== $row['reference_number'], 'overnight shift duration and distinct reference');

$db->exec("UPDATE users SET future_reports_through = '$tomorrow' WHERE id = $owner");
$sendReport($editor, 303, ['report_date' => $tomorrow, 'action' => 'draft']);
$sendReport($editor, 422, ['report_date' => $today->modify('+2 days')->format('Y-m-d'), 'action' => 'draft']);
$otherPortal->request($editor, 422, ['csrf' => $otherPortal->request($editor, 200)->csrf(), 'report_date' => $tomorrow, 'action' => 'draft']);
echo "PASS: staff dashboard, report ownership, validation, drafts, submission, approvals, revisions, attendance and schedule policy\n";
