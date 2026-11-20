<?php
declare(strict_types=1);

// Loaded by authentication.php after it creates the five role fixtures.
use Dtrms\Services\StaffWorkbook;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as ExcelReader;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as ExcelWriter;

$hr = new TestBrowser($argv[1]);
$staffBrowser = new TestBrowser($argv[1]);
$officerBrowser = new TestBrowser($argv[1]);
$loginWith($hr, 'hr_manager@example.test', $newPassword, 303, '/');
$loginWith($staffBrowser, $staff, $resetPassword, 303, '/');
$loginWith($officerBrowser, 'hr_officer@example.test', $newPassword, 303, '/');

$home = '/hr-manager';
$departments = $home . '/departments';
$departmentEdit = $departments . '/edit';
$directory = $home . '/staff';
$userEdit = $directory . '/edit';
$account = $directory . '/access';
$assignment = $directory . '/supervisor';
$import = $directory . '/import';

expect($hr->request($home, 200)->contains('HR dashboard'), 'HR dashboard is visible');
$officerBrowser->request($departments, 403);
$staffBrowser->request($import, 403);
$staffBrowser->request($departmentEdit, 403, ['csrf' => $staffBrowser->request('/', 200)->csrf()]);
$hr->request($departmentEdit, 403, ['code' => 'NOSIGN', 'name' => 'No CSRF']);
$csrf = $hr->request($departmentEdit, 200)->csrf();
$post = static fn (string $path, int $status, array $fields, ?string $location = null): TestBrowser =>
    $hr->request($path, $status, ['csrf' => $csrf] + $fields, $location);

$post($departmentEdit, 303, ['code' => 'HRTEST', 'name' => 'HR test department'], $departments);
$post($departmentEdit, 422, ['code' => 'hrtest', 'name' => 'Duplicate code']);
$post($departmentEdit, 303, ['code' => 'HRMOVE', 'name' => 'Second HR department'], $departments);
$deptId = (int) $db->query("SELECT id FROM departments WHERE code = 'HRTEST'")->fetchColumn();
$otherDeptId = (int) $db->query("SELECT id FROM departments WHERE code = 'HRMOVE'")->fetchColumn();
$deptStatus = $departments . '/status?id=' . $deptId;
$deptForm = $departmentEdit . '?id=' . $deptId;
$post($deptStatus, 303, ['operation' => 'deactivate'], $departments);
expect($hr->request($departments . '?q=HRTEST', 200)->contains('Inactive'), 'department deactivation and search');
$post($deptStatus, 303, ['operation' => 'activate'], $departments);
$extraDepartment = $db->prepare('INSERT INTO departments (code, name) VALUES (?, ?)');
for ($index = 1; $index <= 21; $index++) {
    $extraDepartment->execute(["PAGE-$index", sprintf('Paged department %02d', $index)]);
}
expect($hr->request($departments . '?q=Paged&page=2', 200)->contains('Page 2 of 2'), 'department pagination retains search');

$create = static function (string $id, string $role, string $name, int $status = 200) use ($post, $userEdit, $deptId): TestBrowser {
    return $post($userEdit, $status, [
        'staff_id' => $id, 'name' => $name, 'email' => strtolower($id) . '@example.test',
        'role' => $role, 'department_id' => $deptId,
    ]);
};
$userId = static function (string $id) use ($db): int {
    $query = $db->prepare('SELECT id FROM users WHERE staff_id = ?');
    $query->execute([$id]);
    return (int) $query->fetchColumn();
};

$post($userEdit, 422, ['staff_id' => 'ADM-HR', 'name' => 'Invalid account', 'email' => 'invalid@example.test',
    'role' => 'system_admin', 'department_id' => $deptId]);
$adminId = (int) $db->query("SELECT id FROM users WHERE email = 'admin@example.test'")->fetchColumn();
$hr->request($userEdit . '?id=' . $adminId, 404);
$post($account . '?id=' . $adminId, 404, ['operation' => 'reset']);
$create('HR-001', 'staff', 'HR test staff');
$create('HR-S1', 'supervisor', 'Original supervisor');
$create('HR-S2', 'supervisor', 'Next supervisor');
$create('HR-O1', 'hr_officer', 'HR test officer');
$create('hr-001', 'staff', 'Duplicate identifier', 422);
$post($userEdit, 422, ['staff_id' => 'HR-009', 'name' => 'Duplicate email', 'email' => 'HR-001@EXAMPLE.TEST',
    'role' => 'staff', 'department_id' => $deptId]);

$db->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
    WHERE r.code = 'hr_manager' AND p.code = 'hr_manage_officers'");
$create('HR-O2', 'hr_officer', 'Restricted officer', 422);
$hr->request($userEdit . '?id=' . $userId('HR-O1'), 404);
$db->exec("INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
    WHERE r.code = 'hr_manager' AND p.code = 'hr_manage_officers'");

$subject = $userId('HR-001');
$original = $userId('HR-S1');
$replacement = $userId('HR-S2');
$accountPath = $account . '?id=' . $subject;
$assignPath = $assignment . '?id=' . $subject;
$editPath = $userEdit . '?id=' . $subject;
$post($deptStatus, 422, ['operation' => 'deactivate']);
$post($deptForm, 303, ['code' => 'HRTEST', 'name' => 'HR test department', 'hod_id' => $subject], $departments);
expect($hr->request($departments . '?q=HRTEST', 200)->contains('HR test staff'), 'department HOD displayed');
$post($accountPath, 422, ['operation' => 'deactivate']);
$editFields = ['staff_id' => 'HR-001', 'name' => 'Updated staff member', 'email' => 'hr-001@example.test', 'department_id' => $otherDeptId];
$post($editPath, 422, $editFields);
$post($deptForm, 303, ['code' => 'HRTEST', 'name' => 'HR test department', 'hod_id' => ''], $departments);
$post($editPath, 303, $editFields, $editPath);
expect($hr->request($directory . '?department=' . $otherDeptId . '&q=HR-001', 200)->contains('Updated staff member'), 'department staff filter');

$reportInsert = $db->prepare('INSERT INTO daily_reports (staff_id, department_id, report_date, content, status)
    VALUES (?, ?, CURRENT_DATE, ?, ?)');
try {
    $reportInsert->execute([$subject, $otherDeptId, 'test report', 'submitted']);
    expect(false, 'creation of a submitted report without a supervisor must fail');
} catch (PDOException $error) {
    expect($error->getCode() === '45000', 'insert blocks submission without a supervisor');
}
$reportInsert->execute([$subject, $otherDeptId, 'test report', 'draft']);
$report = (int) $db->lastInsertId();
try {
    $db->exec("UPDATE daily_reports SET status = 'submitted' WHERE id = $report");
    expect(false, 'draft submission without a supervisor must fail');
} catch (PDOException $error) {
    expect($error->getCode() === '45000', 'update blocks submission without a supervisor');
}
$post($assignPath, 422, ['supervisor_id' => $subject, 'reason' => 'Invalid supervisor']);
$post($assignPath, 303, ['supervisor_id' => $original, 'reason' => 'Initial reporting line'], $assignPath);
$db->exec("UPDATE daily_reports SET status = 'submitted' WHERE id = $report");
$db->exec("INSERT INTO report_revisions (report_id, revision_number, revised_by, content)
    VALUES ($report, 1, $subject, 'test report')");
$db->exec("INSERT INTO report_approvals (report_id, revision_number, reviewer_id, stage, decision)
    VALUES ($report, 1, $original, 'supervisor', 'approved')");
$post($account . '?id=' . $original, 422, ['operation' => 'deactivate']);
$post($assignPath, 303, ['supervisor_id' => $replacement, 'reason' => 'Team changed'], $assignPath);
expect($hr->request($assignPath, 200)->contains('Team changed'), 'assignment history shows reason');
expect((int) $db->query("SELECT previous_supervisor_id FROM supervisor_assignments WHERE current_staff_id = $subject")->fetchColumn() === $original,
    'new assignment references previous supervisor');
$post($account . '?id=' . $original, 303, ['operation' => 'deactivate'], $account . '?id=' . $original);
expect((int) $db->query("SELECT reviewer_id FROM report_approvals WHERE report_id = $report")->fetchColumn() === $original,
    'historical approval retains original approver');

$reset = $post($accountPath, 200, ['operation' => 'reset']);
expect(preg_match('/<code class="hr-credential">([a-f0-9]{24})<\/code>/', $reset->body(), $temporary) === 1, 'private temporary password display');
$subjectBrowser = new TestBrowser($argv[1]);
$loginWith($subjectBrowser, 'hr-001@example.test', $temporary[1], 303, '/change-password');
$post($accountPath, 303, ['operation' => 'deactivate'], $accountPath);
$post($accountPath, 303, ['operation' => 'activate'], $accountPath);
$subjectBrowser->request('/change-password', 303, location: '/login');
$loginWith($subjectBrowser, 'hr-001@example.test', $temporary[1], 303, '/change-password');
$post($accountPath, 303, ['operation' => 'force'], $accountPath);
$subjectBrowser->request('/change-password', 303, location: '/login');
foreach (['account_created', 'account_updated', 'account_deactivated', 'account_reactivated', 'password_reset_by_hr',
    'password_change_required', 'supervisor_assignment_changed'] as $event) {
    $query = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE event = ? AND subject_user_id = ? AND actor_id IS NOT NULL');
    $query->execute([$event, $subject]);
    expect((int) $query->fetchColumn() > 0, "$event audited");
}
echo "PASS: HR authorization, departments, staff, account lifecycle, supervisor history and submission guard\n";

if (!StaffWorkbook::available()) {
    expect(!in_array('--require-excel', $argv, true), 'Excel runtime available');
    echo "SKIP: Excel HTTP tests require PhpSpreadsheet.\n";
    return;
}
$template = $hr->request($directory . '/template', 200);
expect(str_contains($template->headers(), 'dtrms-staff-template.xlsx'), 'Excel template attachment');
$templatePath = tempnam(sys_get_temp_dir(), 'dtrms-template-');
$uploadPath = tempnam(sys_get_temp_dir(), 'dtrms-upload-');
$externalPath = $uploadPath . '.links.xlsx';
try {
    file_put_contents($templatePath, $template->body());
    $book = (new ExcelReader())->load($templatePath);
    $sheet = $book->getActiveSheet();
    $sheet->setCellValueExplicit('A2', '00042', DataType::TYPE_STRING);
    $sheet->fromArray(['Excel staff', 'excel@example.test', 'HRTEST'], null, 'B2');
    $sheet->fromArray(['HR-001', 'Duplicate staff', 'duplicate@example.test', 'HRTEST'], null, 'A3');
    $writer = new ExcelWriter($book);
    $send = static function (int $status) use ($hr, $import, $csrf, $uploadPath, $writer): TestBrowser {
        $writer->save($uploadPath);
        return $hr->request($import, $status, ['csrf' => $csrf, 'workbook' => new CURLFile($uploadPath, 'application/octet-stream', 'staff.xlsx')],
            $status === 303 ? '/hr-manager/staff' : null, multipart: true);
    };
    expect($send(422)->contains('Row 3'), 'import reports duplicate workbook row');
    expect($userId('00042') === 0, 'invalid workbook rolls back all rows');
    $sheet->removeRow(3);
    $sheet->setCellValue('B2', '=1+1');
    expect($send(422)->contains('plain text'), 'formulas rejected');
    $sheet->setCellValue('B2', 'Excel staff');
    $writer->save($uploadPath);
    $archive = new ZipArchive();
    expect($archive->open($uploadPath, ZipArchive::RDONLY) === true, 'open synthetic workbook');
    $relationships = $archive->getFromName('_rels/.rels');
    expect(is_string($relationships), 'template relationships available');
    $modified = new ZipArchive();
    expect($modified->open($externalPath, ZipArchive::CREATE) === true, 'create synthetic relationship fixture');
    $modified->addFromString('_rels/.rels', str_replace('</Relationships>',
        '<Relationship Id="qa-external" TargetMode="External" Target="https://example.invalid/" Type="urn:qa"/></Relationships>',
        $relationships));
    $archive->close();
    $modified->close();
    $response = $hr->request($import, 422,
        ['csrf' => $csrf, 'workbook' => new CURLFile($externalPath, 'application/octet-stream', 'staff.xlsx')], multipart: true);
    expect($response->contains('external relationships') && $userId('00042') === 0, 'external links rejected before import');
    $send(303);
    expect($userId('00042') > 0, 'Excel staff imported with leading zeros preserved');
    $book->disconnectWorksheets();
} finally {
    unlink($templatePath);
    unlink($uploadPath);
    if (is_file($externalPath)) {
        unlink($externalPath);
    }
}
echo "PASS: Excel template, validated multipart import, atomic rollback and leading zeros\n";
