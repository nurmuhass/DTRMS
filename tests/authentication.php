<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Services\AccountRecovery;
use Dtrms\Services\Installation;
use Dtrms\Services\PasswordPolicy;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
if (!str_starts_with($config['database']['name'], 'dtrms_test_')
    || !isset($argv[1]) || !preg_match('~\Ahttp://127\.0\.0\.1:[0-9]{1,5}\z~', $argv[1])) {
    fwrite(STDERR, "Use a freshly migrated and seeded dtrms_test_* database and a loopback test server URL.\n");
    exit(2);
}

function expect(bool $condition, string $label): void
{
    $GLOBALS['assertions'] = ($GLOBALS['assertions'] ?? 0) + 1;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

final class TestBrowser
{
    private CurlHandle $handle;
    private string $headers = '';
    private string $body = '';

    public function __construct(private string $origin)
    {
        $this->handle = curl_init();
        curl_setopt_array($this->handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_TIMEOUT => 15,
        ]);
    }

    public function request(string $path, int $expected, ?array $post = null, ?string $location = null, bool $multipart = false, bool $json = false): self
    {
        curl_setopt($this->handle, CURLOPT_URL, $this->origin . $path);
        curl_setopt($this->handle, CURLOPT_HTTPHEADER, $json ? ['Accept: application/json'] : []);
        if ($post === null) {
            curl_setopt($this->handle, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->handle, CURLOPT_POST, true);
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
        }
        $response = curl_exec($this->handle);
        expect(is_string($response), curl_error($this->handle));
        $status = curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
        expect($status === $expected, ($post === null ? 'GET ' : 'POST ') . "$path expected $expected, got $status");
        $size = curl_getinfo($this->handle, CURLINFO_HEADER_SIZE);
        $this->headers = substr($response, 0, $size);
        $this->body = substr($response, $size);
        if ($location !== null) {
            expect(preg_match('/^Location: ' . preg_quote($location, '/') . '\r?$/mi', $this->headers) === 1, "$path redirect to $location");
        }
        return $this;
    }

    public function csrf(): string
    {
        expect(preg_match('/name="csrf" value="([0-9a-f]{64})"/', $this->body, $matches) === 1, 'CSRF token in form');
        return $matches[1];
    }

    public function sessionId(): string
    {
        foreach (curl_getinfo($this->handle, CURLINFO_COOKIELIST) as $cookie) {
            $fields = explode("\t", $cookie);
            if (($fields[5] ?? null) === 'DTRMSSESSID') {
                return $fields[6];
            }
        }
        return '';
    }

    public function contains(string $text): bool
    {
        return str_contains($this->body, $text);
    }

    public function headers(): string
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }
}

$db = Database::connect($config['database']);
$db->exec("UPDATE system_settings SET setting_value='2099-01-05' WHERE setting_key='flexible_schedule_start'");
expect((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'fresh test schema');
$password = bin2hex(random_bytes(16));
$newPassword = bin2hex(random_bytes(16));
$resetPassword = bin2hex(random_bytes(16));
$legacyPassword = 'Legacy fixture password';
$legacyHash = password_hash($legacyPassword, PASSWORD_BCRYPT);
expect(PasswordPolicy::verify($legacyPassword, $legacyHash), 'legacy hashes continue to verify');
$longPassword = str_repeat('b', 80);
$longHash = PasswordPolicy::hash($longPassword);
expect(PasswordPolicy::verify($longPassword, $longHash)
    && !PasswordPolicy::verify($longPassword . 'suffix', $longHash), 'password hashing does not truncate after 72 bytes');
$installation = new Installation($db);
expect($installation->initialize('Test administrator', 'admin@example.test', $password), 'initial administrator installed');
expect(!$installation->initialize('Second administrator', 'second@example.test', $password), 'initializer disabled');
$installation->provisionHrManager('MANAGER-TEST', 'Test HR Manager', 'hr_manager@example.test', $password);
unset($installation);

$workspaces = [
    'staff' => '/staff',
    'supervisor' => '/supervisor',
    'hr_officer' => '/hr-officer',
    'hr_manager' => '/hr-manager',
    'system_admin' => '/administration',
];
$insert = $db->prepare(
    'INSERT INTO users (role_id, name, email, password_hash)
     SELECT id, ?, ?, ? FROM roles WHERE code = ?'
);
foreach (array_keys($workspaces) as $role) {
    if (!in_array($role, ['system_admin', 'hr_manager'], true)) {
        $insert->execute([$role, $role . '@example.test', PasswordPolicy::hash($password), $role]);
        expect($insert->rowCount() === 1, "seeded role $role");
    }
}

$anonymous = new TestBrowser($argv[1]);
$anonymous->request('/', 303, location: '/login');
foreach ($workspaces as $route) {
    $anonymous->request($route, 303, location: '/login');
}
$anonymous->request('/forgot-password', 200);
expect($anonymous->contains('helpdesk'), 'recovery displays internal support instructions');
$anonymous->request('/reset-password', 403, ['code' => 'invalid']);
$anonymous->request('/branding/logo', 404);
$anonymous->request('/no-such-route', 404);

$readRoutes = [
    'staff' => ['/staff/reports' => 200, '/staff/reports/edit' => 200, '/staff/reports/view' => 404],
    'supervisor' => ['/supervisor/reports' => 200, '/supervisor/report' => 404, '/supervisor/history' => 200,
        '/supervisor/approved' => 200, '/supervisor/staff' => 200],
    'hr_review' => ['/hr' => 200, '/hr/reports' => 200, '/hr/reports/approved' => 200,
        '/hr/reports/returned' => 200, '/hr/reports/view' => 404],
    'hr_manager' => ['/hr-manager/departments' => 200, '/hr-manager/departments/edit' => 200,
        '/hr-manager/staff' => 200, '/hr-manager/staff/edit' => 200, '/hr-manager/staff/access' => 404,
        '/hr-manager/staff/supervisor' => 404, '/hr-manager/staff/import' => 200,
        '/hr-manager/staff/template' => \Dtrms\Services\StaffWorkbook::available() ? 200 : 503],
    'system_admin' => ['/administration/settings' => 200, '/administration/roles' => 200,
        '/administration/export' => class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) ? 200 : 503,
        ...array_fill_keys(array_map(static fn (string $key): string => '/administration/' . $key,
            array_keys(\Dtrms\Models\TechnicalMonitoring::SECTIONS)), 200)],
];
$writeRoutes = ['/change-password', '/logout', '/administration/settings', '/administration/settings/logo',
    '/administration/roles', '/staff/reports/edit', '/notifications/read', '/notifications/read-all', '/supervisor/report',
    '/hr/reports/view', '/hr-manager/departments/edit', '/hr-manager/departments/status', '/hr-manager/staff/edit',
    '/hr-manager/staff/access', '/hr-manager/staff/supervisor', '/hr-manager/staff/import'];

foreach ($workspaces as $role => $route) {
    $email = ($role === 'system_admin' ? 'admin' : $role) . '@example.test';
    $browser = new TestBrowser($argv[1]);
    $browser->request('/login', 200);
    expect(stripos($browser->headers(), 'HttpOnly') !== false && stripos($browser->headers(), 'SameSite=Lax') !== false, 'session cookie attributes');
    $anonId = $browser->sessionId();
    $browser->request('/login', 403, ['email' => $email, 'password' => $password]);
    $csrf = $browser->request('/login', 200)->csrf();
    $browser->request('/login', 303, ['email' => strtoupper($email), 'password' => $password, 'csrf' => $csrf], '/change-password');
    expect($anonId !== '' && $browser->sessionId() !== '' && $browser->sessionId() !== $anonId, 'session ID rotated at login');
    $browser->request('/', 303, location: '/change-password');
    $browser->request($route, 303, location: '/change-password');
    $csrf = $browser->request('/change-password', 200)->csrf();
    $browser->request('/change-password', 422, [
        'csrf' => $csrf, 'current_password' => 'incorrect', 'password' => $newPassword, 'confirmation' => $newPassword,
    ]);
    $browser->request('/change-password', 303, [
        'csrf' => $csrf, 'current_password' => $password, 'password' => $newPassword, 'confirmation' => $newPassword,
    ], '/login');
    $browser->request($route, 303, location: '/login');

    $oldLogin = ['csrf' => $browser->request('/login', 200)->csrf(), 'email' => $email, 'password' => $password];
    $browser->request('/login', 401, $oldLogin);
    $browser->request('/login', 303, array_replace($oldLogin, ['password' => $newPassword]), '/');
    $browser->request('/', 200);
    foreach ($workspaces as $targetRole => $targetRoute) {
        $browser->request($targetRoute, $targetRole === $role ? 200 : 403);
    }
    foreach ($readRoutes as $group => $paths) {
        $allowed = $group === $role || ($group === 'hr_review' && in_array($role, ['hr_officer', 'hr_manager'], true));
        foreach ($paths as $path => $status) {
            $browser->request($path, $allowed ? $status : 403);
        }
    }
    $browser->request('/change-password', 200);
    $browser->request('/notifications', 200);
    $browser->request('/notifications/feed', 200, json: true);
    $browser->request('/reports/analytics', $role === 'system_admin' ? 403 : 200);
    $browser->request('/reports/export?format=invalid', in_array($role, ['system_admin', 'hr_officer'], true) ? 403 : 422);
    foreach ($writeRoutes as $path) {
        $browser->request($path, 403, ['csrf' => 'invalid']);
    }

    $browser->request('/logout', 405);
    $browser->request('/logout', 403, ['csrf' => 'invalid']);
    $browser->request('/logout', 303, ['csrf' => $browser->request('/', 200)->csrf()], '/login');
    $browser->request($route, 303, location: '/login');
    echo "PASS: $role route matrix, allowed and denied reads, invalid CSRF on writes\n";
}

expect((int) $db->query('SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL')->fetchColumn() === 5, 'last login recorded for every role');
expect((int) $db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn() === 0, 'logout revoked all test sessions');

$staff = 'staff@example.test';
$browser = new TestBrowser($argv[1]);
$loginWith = static function (TestBrowser $client, string $email, string $secret, int $status, ?string $location = null): void {
    $client->request('/login', $status, [
        'csrf' => $client->request('/login', 200)->csrf(), 'email' => $email, 'password' => $secret,
    ], $location);
};
$loginWith($browser, $staff, $newPassword, 303, '/');
$db->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
    JOIN permissions p ON p.id = rp.permission_id WHERE r.code = 'staff' AND p.code = 'staff_workspace'");
$browser->request('/staff', 403);
$db->exec("INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'staff' AND p.code = 'staff_workspace'");
$browser->request('/staff', 200);
$db->exec('UPDATE user_sessions SET last_seen_at = CURRENT_TIMESTAMP - INTERVAL 31 MINUTE');
$browser->request('/staff', 303, location: '/login');
$loginWith($browser, $staff, $newPassword, 303, '/');
$db->exec('UPDATE user_sessions SET expires_at = CURRENT_TIMESTAMP - INTERVAL 1 MINUTE');
$browser->request('/staff', 303, location: '/login');
$loginWith($browser, $staff, $newPassword, 303, '/');
$db->exec("INSERT INTO departments (code, name) VALUES ('test', 'Test department')");
$db->exec("INSERT INTO daily_reports (staff_id, department_id, report_date, content)
    SELECT u.id, d.id, CURRENT_DATE, 'Test report' FROM users u CROSS JOIN departments d
    WHERE u.email = 'staff@example.test' AND d.code = 'test'");
$db->exec("INSERT INTO report_revisions (report_id, revision_number, revised_by, content)
    SELECT id, 1, staff_id, content FROM daily_reports");
$db->exec("INSERT INTO report_approvals (report_id, revision_number, reviewer_id, stage, decision)
    SELECT r.report_id, r.revision_number, u.id, 'supervisor', 'approved'
    FROM report_revisions r CROSS JOIN users u WHERE u.email = 'supervisor@example.test'");
$db->exec("UPDATE users SET is_active = FALSE WHERE email = 'staff@example.test'");
$browser->request('/staff', 303, location: '/login');
expect((int) $db->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn() === 1, 'report preserved on deactivation');
expect((int) $db->query('SELECT COUNT(*) FROM report_approvals')->fetchColumn() === 1, 'approval preserved on deactivation');
$loginWith($browser, $staff, $newPassword, 401);
expect($browser->contains('Sign-in failed.'), 'inactive account response');
$loginWith($browser, 'missing@example.test', $newPassword, 401);
expect($browser->contains('Sign-in failed.'), 'unknown account receives same sign-in error');
$db->exec("UPDATE users SET is_active = TRUE WHERE email = 'staff@example.test'");
$loginWith($browser, $staff, $newPassword, 303, '/');
$otherBrowser = new TestBrowser($argv[1]);
$recovery = new AccountRecovery($db);
$expired = $recovery->issue($staff);
expect(is_string($expired), 'issue reset code');
$db->exec('UPDATE password_reset_tokens SET expires_at = CURRENT_TIMESTAMP - INTERVAL 1 MINUTE');
$reset = [
    'csrf' => $otherBrowser->request('/reset-password', 200)->csrf(),
    'code' => $expired, 'password' => $resetPassword, 'confirmation' => $resetPassword,
];
$otherBrowser->request('/reset-password', 422, $reset);
$reset['code'] = $recovery->issue($staff);
expect(is_string($reset['code']), 'issue replacement code');
$otherBrowser->request('/reset-password', 303, $reset, '/login');
$browser->request('/staff', 303, location: '/login');
$reset['csrf'] = $otherBrowser->request('/reset-password', 200)->csrf();
$otherBrowser->request('/reset-password', 422, $reset);
$loginWith($otherBrowser, $staff, $newPassword, 401);
$loginWith($otherBrowser, $staff, $resetPassword, 303, '/');

$throttled = new TestBrowser($argv[1]);
for ($attempt = 0; $attempt < 7; $attempt++) {
    $loginWith($throttled, 'admin@example.test', 'wrong-password', 401);
}
$loginWith($throttled, 'admin@example.test', $newPassword, 401);
expect((int) $db->query('SELECT COUNT(*) FROM login_attempts WHERE succeeded = FALSE')->fetchColumn() >= 10, 'failed attempts recorded');
$blocked = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier_hash = ? AND blocked = TRUE AND succeeded = FALSE');
$identifier = hash('sha256', 'admin@example.test');
$blocked->execute([$identifier]);
expect((int) $blocked->fetchColumn() > 0, 'throttled login retained in history');
$db->exec('UPDATE login_attempts SET attempted_at = CURRENT_TIMESTAMP - INTERVAL 16 MINUTE WHERE blocked = FALSE');
$loginWith($throttled, 'admin@example.test', $newPassword, 303, '/');
$blocked->execute([$identifier]);
expect((int) $blocked->fetchColumn() > 0, 'blocked retries did not extend lockout or disappear');
echo "PASS: installation, CSRF, rotation, timeout, activation, recovery, revocation and rate limiting\n";

require __DIR__ . '/hr-cases.php';
require __DIR__ . '/staff-report-cases.php';
require __DIR__ . '/supervisor-cases.php';
require __DIR__ . '/hr-review-cases.php';
require __DIR__ . '/administration-cases.php';
require __DIR__ . '/notification-cases.php';
require __DIR__ . '/reporting-cases.php';
require __DIR__ . '/work-schedule-cases.php';
require __DIR__ . '/leave-cases.php';
require __DIR__ . '/submission-monitoring-cases.php';
require __DIR__ . '/staff-record-cases.php';
require __DIR__ . '/compliance-matrix.php';
require __DIR__ . '/flexible-schedule-cases.php';
require __DIR__ . '/system-audit-cases.php';
echo 'ASSERTIONS PASSED: ' . $GLOBALS['assertions'] . PHP_EOL;
