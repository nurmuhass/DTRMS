<?php
declare(strict_types=1);

// Loaded by authentication.php after the staff reporting test fixtures are available.
use Dtrms\Services\PasswordPolicy;

$passwordUpdate = $db->prepare('UPDATE users SET password_hash = ?, must_change_password = FALSE WHERE id = ?');
$passwordUpdate->execute([PasswordPolicy::hash($newPassword), $replacement]);
$authorizedReviewer = new TestBrowser($argv[1]);
$anotherReviewer = new TestBrowser($argv[1]);
$loginWith($authorizedReviewer, 'hr-s2@example.test', $newPassword, 303, '/');
$loginWith($anotherReviewer, 'supervisor@example.test', $newPassword, 303, '/');
$otherReviewerId = (int) $db->query("SELECT id FROM users WHERE email = 'supervisor@example.test'")->fetchColumn();
$nightId = (int) $db->query("SELECT id FROM daily_reports WHERE staff_id = $owner AND report_date = '$yesterday'")->fetchColumn();
$nightReference = $nightRow['reference_number'];
$reviewPath = '/supervisor/report?id=' . $nightId;
$queue = '/supervisor/reports';
$inboxPath = '/notifications';

expect($authorizedReviewer->request('/supervisor', 200)->contains('Recent approval activity'), 'supervisor dashboard');
expect($authorizedReviewer->request('/supervisor/staff?name=Portal', 200)->contains('Portal Test Staff'), 'assigned roster is searchable');
expect($authorizedReviewer->request($queue . '?name=Portal&date=' . $yesterday, 200)->contains($nightReference), 'pending queue searchable by staff and date');
expect(!$anotherReviewer->request($queue . '?status=all', 200)->contains($nightReference), 'unassigned report excluded from queue');
$anotherReviewer->request($reviewPath, 404);
$portal->request($reviewPath, 403);
$db->exec("UPDATE departments SET hod_id = $otherId WHERE id = $deptId");
$otherPortal->request($reviewPath, 403);

$reviewForm = $authorizedReviewer->request($reviewPath, 200);
expect($reviewForm->contains('4 h 45 min') && $reviewForm->contains('Original daily work'), 'review shows complete work and recorded attendance');
$reviewCsrf = $reviewForm->csrf();
$sendDecision = static fn (TestBrowser $browser, string $csrf, string $path, int $status, string $version, string $decision, string $comments): TestBrowser =>
    $browser->request($path, $status, ['csrf' => $csrf, 'lock_version' => $version, 'decision' => $decision, 'comments' => $comments]);
$unauthorizedCsrf = $anotherReviewer->request('/', 200)->csrf();
$sendDecision($anotherReviewer, $unauthorizedCsrf, $reviewPath, 404, '1', 'approved', 'No assignment');
$authorizedReviewer->request($reviewPath, 403, ['decision' => 'approved', 'lock_version' => '1']);
expect($sendDecision($authorizedReviewer, $reviewCsrf, $reviewPath, 422, '1', 'returned', '  ')->contains('correction reason'), 'return reason is mandatory');
$sendDecision($authorizedReviewer, $reviewCsrf, $reviewPath, 422, '1', 'returned', "\u{00A0}");
$db->exec("UPDATE users SET must_change_password = TRUE WHERE id = $replacement");
$supervisorApproval = new \Dtrms\Services\SupervisorApproval($db, new \Dtrms\Models\SupervisorReports($db),
    new \Dtrms\Models\StaffReports($db), new \Dtrms\Models\Notifications($db));
try {
    $supervisorApproval->decide($replacement, $nightId, '1', 'approved', '');
    expect(false, 'supervisor service refused an ineligible actor');
} catch (DomainException $error) {
    expect($error->getCode() === 404, 'supervisor password gate holds inside transaction');
}
$db->exec("UPDATE users SET must_change_password = FALSE WHERE id = $replacement");
$sendDecision($authorizedReviewer, $reviewCsrf, $reviewPath, 303, '1', 'returned', 'Clarify the overnight achievement');
$sendDecision($authorizedReviewer, $reviewCsrf, $reviewPath, 409, '1', 'returned', 'Repeated form');
expect($db->query("SELECT status FROM daily_reports WHERE id = $nightId")->fetchColumn() === 'returned_by_supervisor', 'supervisor return status');
expect($authorizedReviewer->request('/supervisor/history?decision=returned', 200)->contains($nightReference), 'return appears in approval history');

$noticeId = (int) $db->query("SELECT id FROM notifications WHERE user_id = $owner AND report_id = $nightId AND type = 'report_returned_by_supervisor'")->fetchColumn();
expect($noticeId > 0, 'return notifies report owner');
expect($portal->request($inboxPath, 200)->contains('Your supervisor returned a report for correction'), 'staff sees return in inbox');
expect(!$otherPortal->request($inboxPath, 200)->contains($nightReference), 'notification inbox is recipient-scoped');
$otherPortal->request('/notifications/read', 404, ['csrf' => $otherPortal->request('/', 200)->csrf(), 'id' => $noticeId]);
expect($db->query("SELECT read_at FROM notifications WHERE id = $noticeId")->fetchColumn() === null, 'another account cannot mark notification read');
$portal->request('/notifications/read', 303, ['csrf' => $staffCsrf, 'id' => $noticeId], '/notifications');
expect($db->query("SELECT read_at FROM notifications WHERE id = $noticeId")->fetchColumn() !== null, 'recipient can mark notification read');

$nightEditor = $editor . '?id=' . $nightId;
expect($portal->request($nightEditor, 200)->contains('Clarify the overnight achievement'), 'returned supervisor comment visible during correction');
$corrected = array_replace($night, ['content' => 'Corrected overnight content']);
$sendReport($nightEditor, 303, array_replace($corrected, ['action' => 'draft']) + ['lock_version' => '2']);
expect(!$authorizedReviewer->request($reviewPath, 200)->contains('Corrected overnight content'), 'unsubmitted correction remains private');
$sendReport($nightEditor, 303, $corrected + ['lock_version' => '3']);
$sendDecision($authorizedReviewer, $reviewCsrf, $reviewPath, 409, '1', 'approved', 'Stale original submission');

$post($assignment . '?id=' . $owner, 303, ['supervisor_id' => $otherReviewerId, 'reason' => 'Reassigned during supervisor review']);
$authorizedReviewer->request($reviewPath, 404);
$sendDecision($authorizedReviewer, $reviewCsrf, $reviewPath, 404, '4', 'approved', 'Previous supervisor');
$anotherReviewer->request($reviewPath, 200);
$sendDecision($anotherReviewer, $unauthorizedCsrf, $reviewPath, 303, '4', 'approved', 'Corrected submission accepted');
$sendDecision($anotherReviewer, $unauthorizedCsrf, $reviewPath, 409, '4', 'approved', 'Repeated approval');

expect($db->query("SELECT status FROM daily_reports WHERE id = $nightId")->fetchColumn() === 'pending_hr', 'approval advances to pending_hr');
$reviewRows = $db->query("SELECT reviewer_id, revision_number, decision, created_at FROM report_approvals WHERE report_id = $nightId ORDER BY revision_number")->fetchAll();
expect(count($reviewRows) === 2 && (int) $reviewRows[0]['reviewer_id'] === $replacement && $reviewRows[0]['decision'] === 'returned'
    && (int) $reviewRows[1]['reviewer_id'] === $otherReviewerId && (int) $reviewRows[1]['revision_number'] === 2
    && $reviewRows[1]['created_at'] !== null, 'history preserves original reviewer, timestamp and revision');
expect($anotherReviewer->request('/supervisor/approved?date=' . $yesterday, 200)->contains($nightReference), 'approved reports page');
expect($portal->request($history . '?status=pending_hr', 200)->contains($nightReference), 'staff portal reads pending HR status');
expect($hr->request($inboxPath, 200)->contains($nightReference) && $officerBrowser->request($inboxPath, 200)->contains($nightReference),
    'active HR roles receive inbox notifications');
expect((int) $db->query("SELECT COUNT(*) FROM notifications WHERE report_id = $nightId AND type = 'report_pending_hr'")->fetchColumn()
    === (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active AND r.code IN ('hr_officer','hr_manager')")->fetchColumn(),
    'one notification for each active HR recipient');
expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE object_id = $nightId AND object_type = 'daily_report'
    AND event IN ('supervisor_report_approved','supervisor_report_returned')")->fetchColumn() === 2, 'supervisor decisions audited exactly once');

// Seed a legacy supervisor-owned report assigned to another supervisor to exercise self-review rejection.
$db->exec("INSERT INTO supervisor_assignments (staff_id, supervisor_id, assigned_by) VALUES ($otherReviewerId, $replacement, $replacement)");
$db->exec("INSERT INTO daily_reports (staff_id, department_id, report_date, content, status) VALUES ($otherReviewerId, $deptId, '$yesterday', 'Legacy supervisor report', 'submitted')");
$selfReport = (int) $db->lastInsertId();
$sendDecision($anotherReviewer, $unauthorizedCsrf, '/supervisor/report?id=' . $selfReport, 404, '0', 'approved', 'Own report');

$raceDate = $today->modify('-2 days')->format('Y-m-d');
$sendReport($editor, 303, array_replace($complete, ['report_date' => $raceDate]));
$race = $db->query("SELECT id, lock_version FROM daily_reports WHERE staff_id = $owner AND report_date = '$raceDate'")->fetch();
require __DIR__ . '/review-race.php';
expect(concurrentReview($db, $owner, $otherReviewerId, $race, 'supervisor') === ['409', 'approved'],
    'one concurrent supervisor approval succeeds and one conflicts');

$rollbackDate = $today->modify('-3 days')->format('Y-m-d');
$sendReport($editor, 303, array_replace($complete, ['report_date' => $rollbackDate]));
$rollback = $db->query("SELECT id, lock_version FROM daily_reports WHERE staff_id = $owner AND report_date = '$rollbackDate'")->fetch();
$rollbackId = (int) $rollback['id'];
$db->exec("CREATE TRIGGER test_notification_failure BEFORE INSERT ON notifications FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated notification failure'");
try {
    $sendDecision($anotherReviewer, $unauthorizedCsrf, '/supervisor/report?id=' . $rollbackId, 500,
        (string) $rollback['lock_version'], 'approved', 'Transaction rollback test');
} finally {
    $db->exec('DROP TRIGGER test_notification_failure');
}
expect($db->query("SELECT status FROM daily_reports WHERE id = $rollbackId")->fetchColumn() === 'submitted'
    && (int) $db->query("SELECT COUNT(*) FROM report_approvals WHERE report_id = $rollbackId")->fetchColumn() === 0,
    'notification failure rolls back status and approval');
echo "PASS: supervisor queue, scope, returns, approvals, notifications, reassignment, concurrency and transaction rollback\n";
