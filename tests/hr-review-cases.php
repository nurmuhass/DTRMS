<?php
declare(strict_types=1);

// Loaded after the staff and supervisor suites.
$hrReviewPath = '/hr/reports/view?id=' . $nightId;
$hrQueue = '/hr/reports';
$managerId = (int) $db->query("SELECT id FROM users WHERE email = 'hr_manager@example.test'")->fetchColumn();
$managerForm = $hr->request($hrReviewPath, 200);
$managerCsrf = $managerForm->csrf();
expect($managerForm->contains('Corrected submission accepted') && $managerForm->contains('4 h 45 min')
    && $managerForm->contains('Corrected overnight content') && $managerForm->contains('Approved at'), 'HR sees supervisor identity, comments, work and times');
expect($hr->request('/hr', 200)->contains('Departmental submission statistics'), 'organizational report dashboard');
expect($officerBrowser->request($hrQueue . '?name=Portal&date=' . $yesterday, 200)->contains($nightReference), 'officer can view eligible reports');
expect(!$officerBrowser->request($hrReviewPath, 200)->contains('name="decision"'), 'officer cannot see decision controls');
$sendDecision($officerBrowser, $officerBrowser->request('/', 200)->csrf(), $hrReviewPath, 403, '5', 'approved', 'Read only');
$anotherReviewer->request($hrQueue, 403);
$portal->request($hrReviewPath, 403);
$hr->request($hrReviewPath, 403, ['lock_version' => '5', 'decision' => 'approved']);

$unreviewedPath = '/hr/reports/view?id=' . $rollbackId;
$hr->request($unreviewedPath, 404);
$db->exec("UPDATE daily_reports SET status = 'pending_hr' WHERE id = $rollbackId");
expect(!$hr->request($hrQueue, 200)->contains(report_reference($db->query("SELECT * FROM daily_reports WHERE id = $rollbackId")->fetch())),
    'pending label alone does not put a report in the HR queue');
$sendDecision($hr, $managerCsrf, $unreviewedPath, 404, (string) $rollback['lock_version'], 'approved', 'Bypass attempt');
try {
    $initialReview->execute([$rollbackId, 1, $managerId, 'hr', 'approved', 'Database bypass fixture']);
    expect(false, 'database must stop an HR decision without supervisor approval');
} catch (PDOException $error) {
    expect($error->getCode() === '45000', 'database enforces the same-revision supervisor prerequisite');
}
try {
    $db->exec("UPDATE daily_reports SET status = 'hr_approved' WHERE id = $rollbackId");
    expect(false, 'database must stop a final state without both approvals');
} catch (PDOException $error) {
    expect($error->getCode() === '45000', 'database guards final state');
}
$db->exec("UPDATE daily_reports SET status = 'submitted' WHERE id = $rollbackId");

$revoke = $db->prepare("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
    WHERE r.code = 'hr_manager' AND p.code = ?");
$restore = $db->prepare("INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'hr_manager' AND p.code = ?");
$revoke->execute(['hr_decide_reports']);
$hr->request('/hr-manager', 200);
$sendDecision($hr, $managerCsrf, $hrReviewPath, 403, '5', 'approved', 'Decision permission revoked');
$restore->execute(['hr_decide_reports']);
$revoke->execute(['hr_review_reports']);
$hr->request('/hr-manager', 200);
$hr->request($hrQueue, 403);
$sendDecision($hr, $managerCsrf, $hrReviewPath, 403, '5', 'approved', 'Review permission revoked');
$restore->execute(['hr_review_reports']);
$revoke->execute(['hr_manage_organization']);
$hr->request('/hr-manager', 403);
expect($sendDecision($hr, $managerCsrf, $hrReviewPath, 422, '5', 'returned', '')->contains('correction reason'), 'HR return reason required');
$sendDecision($hr, $managerCsrf, $hrReviewPath, 303, '5', 'returned', 'HR requests clarification of the overnight work');
$restore->execute(['hr_manage_organization']);

expect($db->query("SELECT status FROM daily_reports WHERE id = $nightId")->fetchColumn() === 'returned_by_hr', 'HR return advances to returned_by_hr');
expect($hr->request('/hr/reports/returned', 200)->contains($nightReference), 'returned reports include the HR decision');
expect($portal->request('/notifications', 200)->contains('HR returned your daily report for correction'), 'HR return notifies staff');
expect($portal->request($nightEditor, 200)->contains('HR requests clarification of the overnight work'), 'staff sees HR feedback');
$sendReport($nightEditor, 303, array_replace($corrected, ['content' => 'HR clarification supplied']) + ['lock_version' => '6']);
$hrInbox = $hr->request('/notifications', 200);
expect($hrInbox->contains($nightReference) && !$hrInbox->contains('href="' . $hrReviewPath . '"'),
    'old HR notification stays visible without a link to an unreviewed revision');
$sendDecision($hr, $managerCsrf, $hrReviewPath, 404, '7', 'approved', 'Approval of unreviewed revision');
try {
    $initialReview->execute([$nightId, 3, $managerId, 'hr', 'approved', 'Old supervisor decision is insufficient']);
    expect(false, 'the prior revision approval must not authorize the new submission');
} catch (PDOException $error) {
    expect($error->getCode() === '45000', 'database rejects earlier revision proof');
}
$sendDecision($anotherReviewer, $unauthorizedCsrf, $reviewPath, 303, '7', 'approved', 'Clarification reviewed by supervisor');
$sendDecision($hr, $managerCsrf, $hrReviewPath, 409, '5', 'approved', 'Stale HR form');
$sendDecision($hr, $managerCsrf, $hrReviewPath, 303, '8', 'approved', 'Final HR approval');
$sendDecision($hr, $managerCsrf, $hrReviewPath, 409, '8', 'approved', 'Duplicate HR decision');

expect($db->query("SELECT status FROM daily_reports WHERE id = $nightId")->fetchColumn() === 'hr_approved', 'final report status');
$hrDecisions = $db->query("SELECT revision_number, reviewer_id, decision, comments, created_at FROM report_approvals
    WHERE report_id = $nightId AND stage = 'hr' ORDER BY revision_number")->fetchAll();
expect(count($hrDecisions) === 2 && (int) $hrDecisions[0]['revision_number'] === 2 && $hrDecisions[0]['decision'] === 'returned'
    && (int) $hrDecisions[1]['revision_number'] === 3 && (int) $hrDecisions[1]['reviewer_id'] === $managerId
    && $hrDecisions[1]['comments'] === 'Final HR approval' && $hrDecisions[1]['created_at'] !== null, 'HR history is revision-specific with reviewer and timestamp');
expect($officerBrowser->request('/hr/reports/approved', 200)->contains($nightReference), 'approved page shows final report');
expect($portal->request('/notifications', 200)->contains('HR approved your daily report'), 'staff receives approval notice');
expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE object_id = $nightId AND actor_id = $managerId
    AND event IN ('hr_report_approved','hr_report_returned')")->fetchColumn() === 2, 'HR decisions audited once');

$raceId = (int) $race['id'];
$hrRace = $db->query("SELECT id, lock_version FROM daily_reports WHERE id = $raceId")->fetch();
expect(concurrentReview($db, $owner, $managerId, $hrRace, 'hr') === ['409', 'approved'],
    'one concurrent HR approval succeeds and one conflicts');
expect((int) $db->query("SELECT COUNT(*) FROM notifications WHERE report_id = $raceId AND type = 'report_hr_approved'")->fetchColumn() === 1,
    'concurrent approval emits one notice');

$sendDecision($anotherReviewer, $unauthorizedCsrf, '/supervisor/report?id=' . $rollbackId, 303,
    (string) $rollback['lock_version'], 'approved', 'Supervisor approval for rollback test');
$hrRollbackVersion = (string) $db->query("SELECT lock_version FROM daily_reports WHERE id = $rollbackId")->fetchColumn();
$db->exec("CREATE TRIGGER test_hr_notification_failure BEFORE INSERT ON notifications FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated HR notification failure'");
try {
    $sendDecision($hr, $managerCsrf, $unreviewedPath, 500, $hrRollbackVersion, 'approved', 'Rollback final approval');
} finally {
    $db->exec('DROP TRIGGER test_hr_notification_failure');
}
expect($db->query("SELECT status FROM daily_reports WHERE id = $rollbackId")->fetchColumn() === 'pending_hr'
    && (int) $db->query("SELECT COUNT(*) FROM report_approvals WHERE report_id = $rollbackId AND stage = 'hr'")->fetchColumn() === 0,
    'notification failure rolls back the final state and decision');
echo "PASS: sequential approval, HR permissions, same-revision proof, returns, duplicate protection, concurrency and rollback\n";
