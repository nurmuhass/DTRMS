<?php
declare(strict_types=1);

use Dtrms\Models\Notifications;
use Dtrms\Models\ReportAnalytics;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\ReportFilters;
use Dtrms\Services\ReportReminders;
use Dtrms\Services\ReportingSummary;

$leaveBase = '/hr-manager/leaves';
foreach (['dashboard', 'queue', 'current', 'upcoming', 'history', 'calendar'] as $mode) {
    $hr->request($leaveBase . '?mode=' . $mode, 200);
}
$leaveCsrf = $hr->request($leaveBase . '/edit', 200)->csrf();
$hr->request($leaveBase . '?date=2026-02-30', 422);
$officerBrowser->request($leaveBase, 403);
$portal->request($leaveBase . '/edit', 403);
$adminBrowser->request($leaveBase, 403);
$hr->request($leaveBase . '/edit', 403, ['csrf' => 'invalid']);

$firstDay = $monday->format('Y-m-d');
$lastDay = $monday->modify('+6 days')->format('Y-m-d');
$filtersFor = static fn (int $id): ReportFilters => ReportFilters::parse(
    ['from' => $firstDay, 'until' => $lastDay, 'staff' => (string) $id], $monday);
$cutoff = $monday->modify('+7 days')->setTime(23, 59);
$summary = new ReportingSummary(new ReportAnalytics($db), OperationalPolicy::DEFAULTS, new StaffWorkSchedules($db), new StaffLeaves($db), new \Dtrms\Models\WorkHolidays($db));
$totals = static function (int $id) use ($summary, $filtersFor, $cutoff): array {
    $departments = $summary->build('staff', $id, $filtersFor($id), $cutoff)['departments'];
    return array_map(static fn (string $key): int => array_sum(array_column($departments, $key)),
        ['expected', 'actual', 'fulfilled', 'missing']);
};
$existingReport = $db->prepare("INSERT INTO daily_reports
    (staff_id, department_id, report_date, content, status, submitted_at, first_submitted_at)
    VALUES (?, ?, ?, 'Preserve this existing report', 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$existingReport->execute([$owner, $deptId, $firstDay]);
$preserved = (int) $db->lastInsertId();
expect($totals($owner) === [3, 1, 1, 2] && $totals($otherId) === [3, 0, 0, 3], 'leave baseline follows distinct individual calendars');

$leaveFields = [
    'csrf' => $leaveCsrf, 'user_id' => $owner, 'leave_type' => 'annual',
    'start_date' => $firstDay, 'end_date' => $lastDay, 'reason' => 'Test approved absence',
];
$hr->request($leaveBase . '/edit', 422, array_replace($leaveFields, ['leave_type' => 'unknown']));
$hr->request($leaveBase . '/edit', 422, array_replace($leaveFields, ['end_date' => $monday->modify('-1 day')->format('Y-m-d')]));
$hr->request($leaveBase . '/edit', 422, array_replace($leaveFields, ['user_id' => $managerId]));
$privateStorage = getenv('LEAVE_STORAGE_DIR');
$uploadEnabled = is_string($privateStorage) && is_dir($privateStorage);
if ($uploadEnabled) {
    $temp = tempnam(sys_get_temp_dir(), 'dtrms-leave-');
    try {
        file_put_contents($temp, 'Not a supporting document');
        $hr->request($leaveBase . '/edit', 422, $leaveFields + ['attachment' => new CURLFile($temp, 'application/pdf', 'declared.pdf')], multipart: true);
        $testPdf = $hrPdf ?? "%PDF-1.4\n%%EOF\n";
        file_put_contents($temp, $testPdf);
        $hr->request($leaveBase . '/edit', 303, $leaveFields + ['attachment' => new CURLFile($temp, 'application/octet-stream', 'private-filename.pdf')], multipart: true);
    } finally {
        unlink($temp);
    }
} else {
    $hr->request($leaveBase . '/edit', 303, $leaveFields);
}
$lastLeave = static fn (): int => (int) $db->query('SELECT MAX(id) FROM staff_leaves')->fetchColumn();
$leaveId = $lastLeave();
$detailPath = $leaveBase . '/view?id=' . $leaveId;
$docPath = $leaveBase . '/document?id=' . $leaveId;
$portal->request($docPath, 403);
$otherPortal->request($detailPath, 403);
$officerBrowser->request($detailPath, 403, ['csrf' => $officerBrowser->request('/', 200)->csrf(), 'decision' => 'approve']);
if ($uploadEnabled) {
    $download = $hr->request($docPath, 200);
    expect($download->body() === $testPdf && str_contains($download->headers(), 'attachment; filename="leave-document.pdf"'),
        'authorized download serves original bytes under a generic filename');
    $stored = $db->query("SELECT attachment_path FROM staff_leaves WHERE id=$leaveId")->fetchColumn();
    expect(is_file($privateStorage . DIRECTORY_SEPARATOR . $stored), 'attachment stored at the configured private location');
    $hr->request('/assets/' . $stored, 404);
}
expect($totals($owner) === [3, 1, 1, 2], 'pending leave is not an exemption');
expect($hr->request($leaveBase . '?mode=queue&q=PORTAL-1&department=' . $deptId . '&date=' . $firstDay, 200)
    ->contains('Portal Test Staff'), 'leave queue filters by department, date and staff ID');
$hr->request($leaveBase . '/edit?id=' . $leaveId, 303, array_replace($leaveFields, ['version' => '0', 'reason' => 'Amended leave reason']));
$decideLeave = static function (int $id, string $version, string $decision, int $status, array $extra = []) use ($hr, $leaveCsrf, $leaveBase): void {
    $hr->request($leaveBase . '/view?id=' . $id, $status, ['csrf' => $leaveCsrf, 'version' => $version, 'decision' => $decision] + $extra);
};
$decideLeave($leaveId, '0', 'approve', 409);
$decideLeave($leaveId, '1', 'approve', 303);
$decideLeave($leaveId, '1', 'approve', 409);
expect($totals($owner) === [0, 1, 0, 0], 'full-week leave exempts all own scheduled days and retains actual reports');
expect((int) $db->query("SELECT COUNT(*) FROM daily_reports WHERE id=$preserved")->fetchColumn() === 1, 'existing submitted report survives leave approval');
expect($hr->request($leaveBase . '?mode=upcoming&q=PORTAL-1', 200)->contains('Portal Test Staff'), 'upcoming list shows approved leave');

$wednesday = $monday->modify('+2 days')->format('Y-m-d');
$thursday = $monday->modify('+3 days')->format('Y-m-d');
$friday = $monday->modify('+4 days')->format('Y-m-d');
$hr->request($leaveBase . '/edit', 303, array_replace($leaveFields, ['start_date' => $wednesday, 'end_date' => $friday]));
$overlapId = $lastLeave();
$decideLeave($overlapId, '0', 'approve', 409);
$decideLeave($overlapId, '0', 'approve', 422, ['allow_overlap' => '1']);
$decideLeave($overlapId, '0', 'approve', 303, ['allow_overlap' => '1', 'overlap_reason' => 'Explicit HR authorization']);
expect($totals($owner) === [0, 1, 0, 0], 'approved overlap does not double count exemptions');

$hr->request($leaveBase . '/edit', 303, array_replace($leaveFields, ['user_id' => $otherId, 'start_date' => $thursday, 'end_date' => $friday]));
$partialId = $lastLeave();
$decideLeave($partialId, '0', 'approve', 303);
expect($totals($otherId) === [2, 0, 0, 2], 'partial-week leave exempts Thursday only for Tuesday/Thursday/Saturday staff');
$calendar = $hr->request($leaveBase . '?mode=calendar&month=' . substr($thursday, 0, 7) . '&q=PORTAL-1', 200);
expect($calendar->contains('1 on leave') && !$calendar->contains('2 on leave'), 'calendar counts each staff member once for approved overlaps');

$reminders = new ReportReminders($db, new Notifications($db));
$reminderSettings = array_replace(OperationalPolicy::DEFAULTS, ['reminders_enabled' => true]);
$reminders->schedule(new DateTimeImmutable($thursday . ' 19:00:00'), $reminderSettings);
$event = $db->prepare('SELECT COUNT(*) FROM notification_events WHERE event_key = ?');
$event->execute([hash('sha256', $otherId . ':reminder_missing_report:0:' . $thursday)]);
expect((int) $event->fetchColumn() === 0, 'approved partial leave skips missing-report reminder on an assigned day');
$tuesday = $monday->modify('+1 day')->format('Y-m-d');
$reminders->schedule(new DateTimeImmutable($tuesday . ' 19:00:00'), $reminderSettings);
$event->execute([hash('sha256', $otherId . ':reminder_missing_report:0:' . $tuesday)]);
expect((int) $event->fetchColumn() === 1, 'scheduled day outside leave still gets a reminder');

$decideLeave($leaveId, '2', 'cancel', 303, ['decision_note' => 'Leave cancelled for testing']);
expect($totals($owner) === [1, 1, 1, 0], 'cancellation restores expectations except days covered by other approved leave');
expect($hr->request($detailPath, 200)->contains('Approved by'), 'cancellation retains original approval identity');
if ($uploadEnabled) {
    $hr->request($docPath, 200);
}
$hr->request($leaveBase . '/edit', 303, array_replace($leaveFields, ['end_date' => $firstDay]));
$rejectId = $lastLeave();
$decideLeave($rejectId, '0', 'reject', 422);
$decideLeave($rejectId, '0', 'reject', 303, ['decision_note' => 'Rejected test record']);
expect($totals($owner) === [1, 1, 1, 0], 'rejected leave does not exempt the scheduled day');
$history = $hr->request($leaveBase . '?mode=history&q=PORTAL-1', 200);
expect($history->contains('Cancelled') && $history->contains('Rejected'), 'history includes rejected and cancelled records');
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$hr->request($leaveBase . '/edit', 303, array_replace($leaveFields, ['start_date' => $todayDate, 'end_date' => $todayDate]));
$decideLeave($lastLeave(), '0', 'approve', 303);
expect($hr->request($leaveBase . '?mode=current', 200)->contains('Portal Test Staff'), 'current list includes approved leave covering today');
$events = $db->query("SELECT DISTINCT event FROM audit_logs WHERE object_type='staff_leave' AND actor_id=$managerId")->fetchAll(PDO::FETCH_COLUMN);
foreach (['registered', 'modified', 'approved', 'rejected', 'cancelled'] as $action) {
    expect(in_array('staff_leave_' . $action, $events, true), "leave $action audited");
}
echo 'PASS: leave permissions, approval transitions, calendar, exemptions, reminders and audit'
    . ($uploadEnabled ? ", with private document upload and download\n" : "\n");
