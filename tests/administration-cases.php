<?php
declare(strict_types=1);

// Loaded by authentication.php against its disposable database.
use Dtrms\Models\SystemConfiguration;
use Dtrms\Models\TechnicalMonitoring;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\PasswordPolicy;

// The initial administrator was deliberately rate-limited by the authentication suite.
$technicalFixture = $db->prepare("INSERT INTO users (role_id, name, email, password_hash, must_change_password)
    SELECT id, ?, ?, ?, FALSE FROM roles WHERE code = 'system_admin'");
$technicalFixture->execute(['Technical QA', 'technical-qa@example.test', PasswordPolicy::hash($newPassword)]);
$adminId = (int) $db->lastInsertId();
$adminBrowser = new TestBrowser($argv[1]);
$loginWith($adminBrowser, 'technical-qa@example.test', $newPassword, 303, '/');
$settingsPath = '/administration/settings';
$rolesPath = '/administration/roles';
$technical = $adminBrowser->request('/administration', 200);
expect($technical->contains('Departmental activity') && $technical->contains('Scheduled task execution'), 'technical dashboard shows aggregates and operations');
expect(!$technical->contains('Final HR approval') && !$technical->contains('Corrected daily work'), 'dashboard does not expose report contents or comments');
foreach (array_keys(TechnicalMonitoring::SECTIONS) as $section) {
    $path = '/administration/' . $section;
    $adminBrowser->request($path, 200);
    $hr->request($path, 403);
}
$hr->request($settingsPath, 403);
$officerBrowser->request($rolesPath, 403);
$adminBrowser->request('/hr', 403);
$adminBrowser->request('/hr-manager/staff', 403);
$adminBrowser->request('/hr/reports/view?id=' . $nightId, 403);
$adminForm = $adminBrowser->request($settingsPath, 200);
$adminCsrf = $adminForm->csrf();
$hr->request($settingsPath, 403, ['csrf' => $managerCsrf]);
$hr->request($rolesPath, 403, ['csrf' => $managerCsrf]);
$adminBrowser->request('/hr-manager/staff/edit', 403, ['csrf' => $adminCsrf]);
$adminBrowser->request('/hr/reports/view?id=' . $nightId, 403, ['csrf' => $adminCsrf, 'decision' => 'approved']);
$adminBrowser->request($settingsPath, 403, ['app_name' => 'Missing token']);

$storedSettings = new SystemConfiguration($db);
$settingsValues = array_replace(OperationalPolicy::DEFAULTS, [
    'app_name' => 'Institutional QA Reporting', 'organization_name' => 'QA Organization',
    'working_days' => [1, 3, 5], 'work_start' => '07:15', 'work_end' => '16:25',
    'arrival_grace' => 10, 'report_deadline' => '17:45', 'reminders_enabled' => true, 'reminder_time' => '15:30',
    'email_enabled' => true, 'smtp_host' => 'mail.example.test', 'email_from' => 'reports@example.test',
    'session_idle_minutes' => 5,
    'backups_enabled' => true, 'backup_frequency' => 'weekly', 'backup_weekday' => 6, 'backup_retention_days' => 14,
]);
$settingsPost = array_map(static fn (mixed $value): mixed => is_array($value) ? $value : (string) (is_bool($value) ? (int) $value : $value), $settingsValues);
$settingsPost += ['csrf' => $adminCsrf, 'version' => SystemConfiguration::version(OperationalPolicy::DEFAULTS),
    'smtp_password' => 'QA-NOT-A-REAL-SMTP-SECRET'];
$adminBrowser->request($settingsPath, 422, array_replace($settingsPost, ['session_idle_minutes' => 1]));
$adminBrowser->request($settingsPath, 303, $settingsPost, $settingsPath);
expect($storedSettings->configuration() === $settingsValues, 'validated settings persisted and secret field discarded');
$adminBrowser->request($settingsPath, 409, $settingsPost);
expect($adminBrowser->request($settingsPath, 200)->contains('Institutional QA Reporting'), 'branding and settings render');
expect($portal->request($editor, 200)->contains('Official hours 07:15') && $portal->contains('Reporting deadline 17:45'), 'staff sees work policy');

$noticeEvent = $db->query("SELECT metadata FROM audit_logs WHERE event = 'system_configuration_updated' ORDER BY id DESC LIMIT 1")->fetchColumn();
expect(is_string($noticeEvent) && str_contains($noticeEvent, 'session_idle_minutes') && !str_contains($noticeEvent, 'QA-NOT-A-REAL')
    && !str_contains($noticeEvent, 'mail.example.test'), 'configuration audit records changed keys only');

$systemRole = (int) $db->query("SELECT role_id FROM users WHERE id = $adminId")->fetchColumn();
$adminRolePage = $rolesPath . '?role=' . $systemRole;
$adminRoleForm = $adminBrowser->request($adminRolePage, 200);
expect(preg_match('/name="version" value="([a-f0-9]{64})"/', $adminRoleForm->body(), $vr) === 1, 'role form includes version');
$adminBrowser->request($adminRolePage, 422, ['csrf' => $adminCsrf, 'version' => $vr[1],
    'name' => 'System Administrator', 'permissions' => ['hr_decide_reports', 'hr_manage_organization']]);
$overgrant = $db->prepare("INSERT INTO role_permissions (role_id, permission_id)
    SELECT ?, id FROM permissions WHERE code IN ('hr_review_reports','hr_decide_reports','hr_manage_organization')");
$overgrant->execute([$systemRole]);
try {
    $adminBrowser->request('/hr', 403);
    $adminBrowser->request('/hr-manager/staff', 403);
    $adminBrowser->request('/hr/reports/view?id=' . $nightId, 403, ['csrf' => $adminCsrf, 'decision' => 'approved']);
} finally {
    $db->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = $systemRole AND p.code IN ('hr_review_reports','hr_decide_reports','hr_manage_organization')");
}

$officerRole = (int) $db->query("SELECT id FROM roles WHERE code = 'hr_officer'")->fetchColumn();
$officerRolePage = $rolesPath . '?role=' . $officerRole;
$roleForm = $adminBrowser->request($officerRolePage, 200);
expect(preg_match('/name="version" value="([a-f0-9]{64})"/', $roleForm->body(), $vr) === 1, 'officer role version');
$update = ['csrf' => $adminCsrf, 'version' => $vr[1], 'name' => 'HR Officer QA',
    'permissions' => ['hr_review_reports', 'hr_officer_workspace', 'hr_decide_reports']];
$adminBrowser->request($officerRolePage, 303, $update, $officerRolePage);
$adminBrowser->request($officerRolePage, 409, $update);
expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event = 'role_permissions_updated' AND object_id = $officerRole")->fetchColumn() === 1,
    'role update recorded exactly once');
expect((int) $db->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = $officerRole AND p.code = 'hr_decide_reports'")->fetchColumn() === 1, 'compatible grant persisted');
$officerBrowser->request($settingsPath, 403);

$passwordForm = $portal->request('/change-password', 200);
expect($passwordForm->contains('Password must contain at least 8 characters.'), 'password page reflects minimum');
$portal->request('/change-password', 422, ['csrf' => $passwordForm->csrf(), 'current_password' => $newPassword,
    'password' => 'Seven7!', 'confirmation' => 'Seven7!']);
$boundaryPassword = bin2hex(random_bytes(4));
$portal->request('/change-password', 303, ['csrf' => $passwordForm->csrf(), 'current_password' => $newPassword,
    'password' => $boundaryPassword, 'confirmation' => $boundaryPassword], '/login');
$loginWith($portal, 'portal-1@example.test', $boundaryPassword, 303, '/');
$db->exec("UPDATE user_sessions SET last_seen_at = CURRENT_TIMESTAMP - INTERVAL 6 MINUTE WHERE user_id = $owner");
$portal->request('/staff', 303, location: '/login');

$sentinel = 'QA-PRIVATE-RECORD-DO-NOT-DISPLAY';
$delivery = $db->prepare("INSERT INTO email_logs (recipient, template, status, provider_message_id) VALUES (?, ?, 'failed', ?)");
$delivery->execute([$sentinel . '@example.test', $sentinel, $sentinel]);
$db->prepare("INSERT INTO audit_logs (event, metadata) VALUES ('qa_security_check', ?)")->execute([json_encode(['detail' => $sentinel])]);
$db->prepare("INSERT INTO scheduled_job_executions (job_name, scheduled_for, status, details)
    VALUES ('report_reminder', '2026-03-01 09:00:00', 'failed', ?)")->execute([$sentinel]);
foreach (['audit', 'email', 'jobs'] as $section) {
    expect(!$adminBrowser->request('/administration/' . $section, 200)->contains($sentinel), "$section hides confidential details");
}
$failure = $db->prepare("INSERT INTO login_attempts (identifier_hash, ip_address) VALUES (?, '192.0.2.42')");
$identifier = hash('sha256', 'private-fixture');
for ($i = 0; $i < 30; $i++) {
    $failure->execute([$identifier]);
}
$data = $adminBrowser->request('/administration/login', 200);
expect($data->contains('Suspicious login activity') && $data->contains('192.0.2.42') && !$data->contains($identifier), 'suspicious sources without identifier digests');

$recordRun = static function (string $status): void {
    $command = [PHP_BINARY, __DIR__ . '/../bin/record-job-run.php', 'database_backup', '2026-03-01T02:00:00Z', '1', $status];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process), 'job recorder process starts');
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process) === 0 && str_contains($out, 'Job run recorded.') && $error === '', 'job recorder succeeds');
};
$recordRun('running');
$recordRun('succeeded');
$recordRun('failed');
expect($adminBrowser->request('/administration/backups', 200)->contains('succeeded'), 'backup run is recorded and terminal state is preserved');
expect($adminBrowser->request('/administration/database', 200)->contains('Connected · Probe time'), 'database probe works');

if (function_exists('imagecreatetruecolor')) {
    $testFile = tempnam(sys_get_temp_dir(), 'dtrms-logo-');
    try {
        imagepng(imagecreatetruecolor(4, 4), $testFile);
        $upload = ['csrf' => $adminCsrf, 'action' => 'upload', 'logo' => new CURLFile($testFile, 'image/png', 'logo.png')];
        $adminBrowser->request($settingsPath . '/logo', 303, $upload, $settingsPath, multipart: true);
        $logo = $anonymous->request('/branding/logo', 200);
        expect(str_contains($logo->headers(), 'Content-Type: image/png') && str_starts_with($logo->body(), "\x89PNG"), 'canonical logo served');
        file_put_contents($testFile, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $adminBrowser->request($settingsPath . '/logo', 422, $upload, multipart: true);
        $adminBrowser->request($settingsPath . '/logo', 303, ['csrf' => $adminCsrf, 'action' => 'remove'], $settingsPath);
        $anonymous->request('/branding/logo', 404);
    } finally {
        unlink($testFile);
    }
} else {
    expect(!in_array('--require-excel', $argv, true), 'GD required for logo integration coverage');
    echo "SKIP: logo upload requires GD.\n";
}
echo "PASS: technical admin boundaries, settings, branding, grants, live security policy, log privacy, and backup monitoring\n";
