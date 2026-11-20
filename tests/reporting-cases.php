<?php
declare(strict_types=1);

// Loaded by authentication.php against the disposable integration database.
use Dtrms\Models\ReportAnalytics;
use Dtrms\Services\ReportFilters;
use Dtrms\Services\ReportingSummary;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

$analyticsPath = '/reports/analytics';
$exportPath = '/reports/export';
$dayQuery = http_build_query(['from' => $yesterday, 'until' => $yesterday, 'department' => $deptId,
    'staff' => $owner, 'supervisor' => $otherReviewerId, 'status' => 'hr_approved']);
$hrAnalytics = $hr->request($analyticsPath . '?' . $dayQuery, 200);
expect($hrAnalytics->contains($nightReference) && $hrAnalytics->contains('data-report-charts')
    && $hrAnalytics->contains('Staff-entered arrival times'), 'analytics use scoped filters and actual report reference');
expect(!$hrAnalytics->contains($row['reference_number']), 'range excludes another reporting date');
expect($hr->request('/assets/chart.umd.min.js', 200)->contains('Chart.js v4.5.1'), 'same-origin Chart.js is served');
$hr->request($analyticsPath . '?from=2026-02-30&until=2026-03-01', 422);
$hr->request($analyticsPath . '?month=13&year=2026', 422);
$hr->request($analyticsPath . '?from=2025-01-01&until=2026-12-31', 422);
$hr->request($analyticsPath . '?from=2026-10-01&until=2026-10-31&month=11&year=2026', 422);
$hr->request($analyticsPath . '?year=' . $today->format('Y') . '&month=' . substr($yesterday, 5, 2), 200);
expect(!$hr->request($analyticsPath . '?' . str_replace('status=hr_approved', 'status=submitted', $dayQuery), 200)->contains($nightReference),
    'approval status excludes the approved report');
expect(!$hr->request($analyticsPath . '?' . str_replace('supervisor=' . $otherReviewerId, 'supervisor=' . $replacement, $dayQuery), 200)->contains($nightReference),
    'supervisor filter uses current assignment');
$adminBrowser->request($analyticsPath, 403);

$reportModel = new ReportAnalytics($db);
$calendarPolicy = array_replace($storedSettings->configuration(), ['working_days' => [1,2,3,4,5,6,7]]);
$summaryModel = new ReportingSummary($reportModel, $calendarPolicy, new \Dtrms\Models\StaffWorkSchedules($db), new \Dtrms\Models\StaffLeaves($db), new \Dtrms\Models\WorkHolidays($db));
$db->exec("UPDATE system_settings SET setting_value = JSON_SET(setting_value, '$.working_days', JSON_ARRAY(1,2,3,4,5,6,7))
    WHERE setting_key = 'staff_schedule_calendar'");
$db->exec("UPDATE users SET created_at = '2026-01-01 08:00:00' WHERE id = $owner");
$asOf = $today->setTime(23, 59);
$makeSummary = static function (string $day, string $status = '') use ($summaryModel, $managerId, $owner, $deptId, $asOf): array {
    $filters = ReportFilters::parse(['from' => $day, 'until' => $day, 'department' => (string) $deptId,
        'staff' => (string) $owner, 'status' => $status], $asOf);
    return $summaryModel->build('organization', $managerId, $filters, $asOf);
};
$monthRow = $makeSummary($yesterday, 'submitted');
$department = $monthRow['departments'][0];
expect($department['staff'] === 1 && $department['expected'] === 1 && $department['actual'] === 1
    && $department['missing'] === 0 && $department['completion'] === 100.0 && $monthRow['stats']['submitted'] === 0,
    'monthly targets count all statuses while charts honor the status filter');
$pendingRow = $makeSummary($pendingDate)['departments'][0];
expect($pendingRow['pending'] === 1 && $pendingRow['actual'] === 1 && $pendingRow['missing'] === 0, 'pending review is a submission');
$missingRow = $makeSummary($today->modify('-9 days')->format('Y-m-d'))['departments'][0];
expect($missingRow['actual'] === 0 && $missingRow['missing'] === 1, 'eligible person-date without a submission is missing');
$dayAttendance = $summaryModel->build('staff', $owner, ReportFilters::parse(['from' => $date, 'until' => $date], $asOf), $asOf);
expect($dayAttendance['stats']['late'] === 1 && $dayAttendance['stats']['assessed'] === 1, 'official start and grace classify late staff-entered arrival');
$overnightAttendance = $makeSummary($yesterday);
expect($overnightAttendance['stats']['assessed'] === 0 && $overnightAttendance['stats']['minutes'] === 285, 'overnight duration retained outside lateness assessment');

if (!class_exists(Xlsx::class) || !class_exists(\Dompdf\Dompdf::class)) {
    expect(!in_array('--require-excel', $argv, true), 'export libraries available');
    echo "SKIP: generated exports require Composer libraries.\n";
    return;
}

$payload = $exportPath . '?id=' . $nightId;
$otherPortal->request($payload . '&format=pdf', 404);
$authorizedReviewer->request($payload . '&format=xlsx', 404);
$officerBrowser->request($payload . '&format=xlsx', 403);
$adminBrowser->request($payload . '&format=pdf', 403);
$hr->request('/administration/export', 403);
$otherPortal->request('/administration/export', 403);
$hr->request($exportPath . '?format=pdf', 422);
$hr->request($exportPath . '?type=unexpected', 422);

$formulaLike = '=QA_TEXT_WITHOUT_EXECUTION';
$db->prepare('UPDATE daily_reports SET content = ? WHERE id = ?')->execute([$formulaLike, $nightId]);
$db->prepare("UPDATE report_revisions SET content = ?, snapshot = JSON_SET(snapshot, '$.content', ?)
    WHERE report_id = ? AND revision_number = 3")->execute([$formulaLike, $formulaLike, $nightId]);
ob_start();
imagepng(imagecreatetruecolor(12, 8));
$testLogo = (string) ob_get_clean();
$storedSettings->updateLogo($adminId, $testLogo);

$pdfBytes = $portal->request($payload . '&format=pdf', 200)->body();
expect(str_starts_with($pdfBytes, '%PDF-') && str_contains($portal->headers(), 'application/pdf'), 'staff can download own PDF');
$supervisorPdf = $anotherReviewer->request($payload . '&format=pdf', 200)->body();
expect(str_starts_with($supervisorPdf, '%PDF-'), 'current supervisor can export assigned report');
$hrPdf = $hr->request($payload . '&format=pdf', 200)->body();
expect(str_starts_with($hrPdf, '%PDF-'), 'authorized HR can export individual report');
$xlsx = $hr->request($payload . '&format=xlsx', 200)->body();
expect(str_contains($hr->headers(), 'spreadsheetml.sheet'), 'Excel content type');

$fixtureFolder = getenv('DTRMS_EXPORT_FIXTURES');
$loadWorkbook = static function (string $bytes) {
    $path = tempnam(sys_get_temp_dir(), 'dtrms-export-');
    try {
        file_put_contents($path, $bytes);
        return (new Xlsx())->load($path);
    } finally {
        unlink($path);
    }
};
$book = $loadWorkbook($xlsx);
expect($book->getSheetCount() === 2 && $book->getSheet(1)->getTitle() === 'Approval history', 'individual export contains approval history');
$literal = null;
foreach ($book->getSheet(0)->getRowIterator(5) as $record) {
    $address = 'B' . $record->getRowIndex();
    $cell = $book->getSheet(0)->getCell($address);
    if ($cell->getValue() === $formulaLike) {
        $literal = $cell->getDataType();
    }
}
expect($literal === DataType::TYPE_STRING, 'formula-like text is exported as an Excel string');
$book->disconnectWorksheets();
$workbooks = ['department' => 'Departmental monthly summary', 'staff' => 'Staff submissions',
    'attendance' => 'Staff-entered attendance', 'approval' => 'Approval activity'];
foreach ($workbooks as $type => $title) {
    $response = $hr->request($exportPath . '?type=' . $type . '&' . $dayQuery, 200);
    $book = $loadWorkbook($response->body());
    expect($book->getActiveSheet()->getTitle() === $title && $book->getActiveSheet()->getHighestDataRow() >= 5,
        "$type workbook has database rows");
    if ($type === 'department') {
        expect($book->getActiveSheet()->getCell('E5')->getValue() === 1 && $book->getActiveSheet()->getCell('F5')->getValue() === 0,
            'departmental workbook reports correct actual and missing totals');
    }
    if (is_string($fixtureFolder) && is_dir($fixtureFolder)) {
        file_put_contents($fixtureFolder . '/' . $type . '.xlsx', $response->body());
    }
    $book->disconnectWorksheets();
}
$adminWorkbook = $adminBrowser->request('/administration/export', 200)->body();
$book = $loadWorkbook($adminWorkbook);
expect($book->getSheetCount() === 2 && $book->getSheet(0)->getCell('A5')->getValue() === 'Registered users'
    && $book->getSheet(1)->getTitle() === 'Department activity', 'admin workbook contains operational aggregates only');
$book->disconnectWorksheets();
if (is_string($fixtureFolder) && is_dir($fixtureFolder)) {
    file_put_contents($fixtureFolder . '/report.pdf', $hrPdf);
    file_put_contents($fixtureFolder . '/individual.xlsx', $xlsx);
    file_put_contents($fixtureFolder . '/administrative.xlsx', $adminWorkbook);
}
echo "PASS: report filters, monthly metrics, attendance and lateness, PDF and all Excel exports, scope and formula safety\n";

expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event='administrative_summary_exported' AND actor_id=$adminId")->fetchColumn() > 0,
    'administrative export writes an audit event');
expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event='daily_report_exported' AND object_type='daily_report'")->fetchColumn() > 0,
    'daily report export writes an audit event');
