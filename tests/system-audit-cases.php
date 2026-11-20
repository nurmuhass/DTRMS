<?php
declare(strict_types=1);

$monitoringPath = '/hr-manager/submissions';
$missingFilter = ['period' => 'week', 'week' => $futureWeek->format('o-\WW'),
    'schedule' => 'missing', 'sort' => 'outstanding'];
$monitoring = $hr->request($monitoringPath . '?' . http_build_query($missingFilter), 200);
expect($monitoring->contains('Export filtered staff to Excel'), 'matching schedule filter offers an Excel export');
$exportForm = $missingFilter + ['csrf' => $monitoring->csrf()];
$hr->request($monitoringPath . '/export', 403, $missingFilter);
$selfFlex->request($monitoringPath . '/export', 403, $exportForm);
$officerBrowser->request($monitoringPath . '/export', 403, $exportForm);
$adminBrowser->request($monitoringPath . '/export', 403, $exportForm);
$exportGrant = $db->query("SELECT r.id AS role_id, p.id AS permission_id FROM roles r CROSS JOIN permissions p
    WHERE r.code='hr_manager' AND p.code='hr_export_reports'")->fetch();
$db->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?')->execute(array_values($exportGrant));
$hr->request($monitoringPath . '/export', 403, $exportForm);
$db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute(array_values($exportGrant));
$hr->request($monitoringPath . '/export', 422, array_replace($exportForm, ['week' => 'bad']));
if (class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class)) {
    $path = tempnam(sys_get_temp_dir(), 'dtrms-roster-');
    try {
        file_put_contents($path, $hr->request($monitoringPath . '/export', 200, $exportForm)->body());
        $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
        $rows = array_slice($book->getActiveSheet()->toArray(), 4);
        expect(count($rows) > 0, 'missing schedule Excel export contains results');
        foreach ($rows as $row) {
            expect($row[11] === 'Schedule Not Submitted' && $row[1] !== 'FLEX-01', 'Excel contains only staff with missing schedules');
        }
        $book->disconnectWorksheets();
        expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event='submission_monitoring_exported'")->fetchColumn() === 1,
            'successful roster export is audited once');
    } finally {
        unlink($path);
    }
}

$matrixWeek = $futureWeek->modify('+70 days');
$matrixKey = $matrixWeek->format('Y-m-d');
$matrixDates = array_map(static fn (int $day): string => $matrixWeek->modify("+$day days")->format('Y-m-d'), range(0, 6));
$combinations = 0;
for ($first = 0; $first < 5; $first++) {
    for ($second = $first + 1; $second < 6; $second++) {
        for ($third = $second + 1; $third < 7; $third++) {
            $days = [$matrixDates[$first], $matrixDates[$second], $matrixDates[$third]];
            $flexSchedules->change($flexStaff, $flexStaff, $matrixWeek, $days, 'submit', '', false, $todayFlex);
            expect($flexSchedules->get($flexStaff, $matrixKey)['days'] === $days, 'valid distinct date combination is preserved');
            $combinations++;
        }
    }
}
expect($combinations === 35, 'all 35 weekly three-day combinations were tested');
$selfFlex->request($flexPath, 422, array_replace($form, ['days' => [...$futureDays, $futureWeek->format('Y-m-d')]]));
$selfFlex->request($flexPath, 422, array_replace($form, ['days' => [$futureDays[0], $futureDays[1], 'invalid']]));
echo "PASS: filtered Excel export, access restrictions, audit, and all 35 weekly combinations\n";

$journeyAccount = ['staff_id' => 'AUDIT-01', 'name' => 'Audit workflow staff', 'email' => 'audit-01@example.test',
    'role' => 'staff', 'department_id' => $deptId];
expect($post($userEdit, 422, $journeyAccount + ['temporary_password' => 'sevenxx'])->contains(\Dtrms\Services\PasswordPolicy::MESSAGE),
    'HR rejects a seven-character temporary password');
$journeyCreate = $post($userEdit, 200, $journeyAccount + ['temporary_password' => 'abcdefgh']);
expect(preg_match('/<code class="hr-credential">([^<]+)<\/code>/', $journeyCreate->body(), $journeyTemporary) === 1,
    'HR-created staff receives temporary credential');
$journeyId = $userId('AUDIT-01');
$post('/hr-manager/staff/supervisor?id=' . $journeyId, 303, ['supervisor_id' => $otherReviewerId, 'reason' => 'Institutional audit test']);
$journey = new TestBrowser($argv[1]);
$temporaryPassword = html_entity_decode($journeyTemporary[1], ENT_QUOTES | ENT_HTML5);
$loginWith($journey, 'audit-01@example.test', $temporaryPassword, 303, '/change-password');
expect($temporaryPassword === 'abcdefgh', 'HR accepts an eight-character lowercase-only password');
$changeToken = $journey->request('/change-password', 200)->csrf();
expect($journey->request('/change-password', 422, [
    'csrf' => $changeToken, 'current_password' => $temporaryPassword, 'password' => 'sevenxx', 'confirmation' => 'sevenxx',
])->contains(\Dtrms\Services\PasswordPolicy::MESSAGE), 'seven-character password change returns precise validation');
$journeyPassword = str_repeat('w', 95) . 'z';
$journey->request('/change-password', 303, [
    'csrf' => $journey->request('/change-password', 200)->csrf(), 'current_password' => $temporaryPassword,
    'password' => $journeyPassword, 'confirmation' => $journeyPassword,
], '/login');
$loginWith($journey, 'audit-01@example.test', $journeyPassword, 303, '/');
$journeyDays = $futureDays;
$journey->request($flexPath, 303, [
    'csrf' => $journey->request($flexPath, 200)->csrf(), 'action' => 'submit', 'days' => $journeyDays,
]);
$journeyRow = static fn (DateTimeImmutable $asOf): array => $flexMonitor->dashboard(
    ['staff' => $journeyId], $futureWeek, $futureWeek->modify('+6 days'), $asOf)['rows'][0];
expect((int) $journeyRow($todayFlex)['expected'] === 3 && (int) $journeyRow($todayFlex)['actual'] === 0,
    'monitoring sees original three-date obligation');
// Use the supported future-report allowance in the disposable fixture to exercise next week without waiting.
$db->prepare('UPDATE users SET future_reports_through=? WHERE id=?')->execute([$journeyDays[2], $journeyId]);
$journeyToken = $journey->request($editor, 200)->csrf();
$journeyReport = static function (string $date) use ($journey, $journeyToken, $complete, $editor, $db, $journeyId): array {
    $journey->request($editor, 303, ['csrf' => $journeyToken] + array_replace($complete, ['report_date' => $date]));
    $query = $db->prepare('SELECT id, lock_version FROM daily_reports WHERE staff_id=? AND report_date=?');
    $query->execute([$journeyId, $date]);
    return $query->fetch();
};
$firstJourney = $journeyReport($journeyDays[0]);
$journeyReview = '/supervisor/report?id=' . $firstJourney['id'];
$journeyHr = '/hr/reports/view?id=' . $firstJourney['id'];
expect($anotherReviewer->request('/notifications', 200)->contains($journeyReview), 'assigned supervisor receives report notification');
$journeyReviewerToken = $anotherReviewer->request($journeyReview, 200)->csrf();
$sendDecision($anotherReviewer, $journeyReviewerToken, $journeyReview, 303, (string) $firstJourney['lock_version'],
    'approved', 'Supervisor reviewed the work.');
expect($hr->request('/notifications', 200)->contains($journeyHr), 'HR receives supervisor-approved report notification');
$sendDecision($hr, $hr->request($journeyHr, 200)->csrf(), $journeyHr, 303, (string) ($firstJourney['lock_version'] + 1),
    'approved', 'HR reviewed the approved submission.');
$journeyMonitoring = $hr->request($monitoringPath . '?' . http_build_query([
    'week' => $futureWeek->format('o-\WW'), 'q' => 'AUDIT-01', 'department' => $deptId,
]), 200);
expect($journeyMonitoring->contains('Audit workflow staff'), 'HR can find the journey staff member in monitoring');
$afterApproval = $journeyRow($todayFlex);
expect((int) $afterApproval['actual'] === 1 && (int) $afterApproval['approved'] === 1 && (int) $afterApproval['outstanding'] === 2,
    'monitoring shows submission, final approval and two remaining days separately');
$journeyNotifications = $journey->request('/notifications', 200);
expect($journeyNotifications->contains('HR approved your daily report.') && $journeyNotifications->contains('/staff/reports/view?id=' . $firstJourney['id']), 'staff can see final approval notification');
$hr->request($leaveBase . '/edit', 303, [
    'csrf' => $leaveCsrf, 'user_id' => $journeyId, 'leave_type' => 'annual',
    'start_date' => $journeyDays[1], 'end_date' => $journeyDays[1], 'reason' => 'Single selected day exemption',
]);
$decideLeave($lastLeave(), '0', 'approve', 303);
$afterLeave = $journeyRow($todayFlex);
expect((int) $afterLeave['expected'] === 2 && (int) $afterLeave['outstanding'] === 1 && $afterLeave['exemption_dates'] === $journeyDays[1],
    'approved leave removes exactly the matching selected date');
$journeyReport($journeyDays[2]);
$afterSunday = $futureWeek->modify('+7 days');
$results = new \Dtrms\Services\WeeklyResults($db);
expect($results->finalize($afterSunday) > 0 && $results->finalize($afterSunday) === 0, 'Monday finalization writes results once');
$finalJourney = $journeyRow($afterSunday);
expect((int) $finalJourney['submitted'] === 2 && (int) $finalJourney['outstanding'] === 0
    && (float) $finalJourney['percentage'] === 100.0, 'completed staff journey finalizes at 100 percent');
$journeyExport = ['csrf' => $exportForm['csrf'], 'staff' => $journeyId, 'period' => 'week', 'week' => $futureWeek->format('o-\WW')];
$pdf = $hr->request('/reports/staff-record/export', 200, $journeyExport + ['format' => 'pdf'])->body();
expect(str_starts_with($pdf, '%PDF-'), 'HR journey weekly PDF is generated');
if (class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class)) {
    $excel = $hr->request('/reports/staff-record/export', 200, $journeyExport + ['format' => 'xlsx'])->body();
    expect(str_starts_with($excel, 'PK'), 'HR journey weekly Excel is generated');
    $departmentExport = $hr->request('/reports/export?' . http_build_query([
        'type' => 'department', 'department' => $deptId, 'month' => $futureWeek->format('m'), 'year' => $futureWeek->format('Y'),
    ]), 200)->body();
    $departmentBook = $loadWorkbook($departmentExport);
    expect($departmentBook->getActiveSheet()->getTitle() === 'Departmental monthly summary',
        'HR journey departmental monthly workbook is generated');
    $departmentBook->disconnectWorksheets();
    $summaryAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_id=? AND event='reporting_summary_exported'
        AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.type'))='department'");
    $summaryAudit->execute([$managerId]);
    expect((int) $summaryAudit->fetchColumn() > 0, 'departmental monthly export writes an HR audit event');
}
$journeyAudit = $db->prepare('SELECT DISTINCT event FROM audit_logs WHERE subject_user_id=?');
$journeyAudit->execute([$journeyId]);
$recorded = $journeyAudit->fetchAll(PDO::FETCH_COLUMN);
foreach (['account_created', 'supervisor_assignment_changed', 'weekly_schedule_submitted', 'report_submitted',
    'supervisor_report_approved', 'hr_report_approved', 'staff_leave_approved', 'staff_period_exported'] as $event) {
    expect(in_array($event, $recorded, true), "workflow records the $event audit event");
}
echo "PASS: HR account creation to sequential review, approved leave, Sunday close and staff-record exports\n";

$edgeWeek = $cross;
$edgeDates = array_map(static fn (int $day): string => $edgeWeek->modify("+$day days")->format('Y-m-d'), [0, 3, 6]);
$flexSchedules->change($journeyId, $journeyId, $edgeWeek, $edgeDates, 'submit', '', false, $todayFlex);
$edgeLeaves = [
    ['+1 day', '+2 days', []],
    ['-2 days', '+1 day', [$edgeDates[0]]],
    ['+5 days', '+9 days', [$edgeDates[2]]],
    ['+0 days', '+6 days', $edgeDates],
];
foreach ($edgeLeaves as [$start, $end, $exemptions]) {
    $hr->request($leaveBase . '/edit', 303, [
        'csrf' => $leaveCsrf, 'user_id' => $journeyId, 'leave_type' => 'annual',
        'start_date' => $edgeWeek->modify($start)->format('Y-m-d'), 'end_date' => $edgeWeek->modify($end)->format('Y-m-d'),
        'reason' => 'Test a cross-boundary leave period',
    ]);
    $edgeLeave = $lastLeave();
    $decideLeave($edgeLeave, '0', 'approve', 303);
    $edgeRow = $flexMonitor->dashboard(['staff' => $journeyId], $edgeWeek, $edgeWeek->modify('+6 days'), $todayFlex)['rows'][0];
    expect((int) $edgeRow['expected'] === 3 - count($exemptions)
        && ($edgeRow['exemption_dates'] ?? '') === implode(', ', $exemptions),
        'flexible leave excludes only selected dates, including leave crossing a week or month boundary');
    if (count($exemptions) === 3) {
        expect($edgeRow['percentage'] === null, 'a full week of approved leave displays compliance as unavailable');
    }
    $decideLeave($edgeLeave, '1', 'cancel', 303, ['decision_note' => 'Complete the leave boundary fixture']);
}
echo "PASS: flexible leave with no selected dates, week-spanning leave in both directions, and full cross-month leave\n";
