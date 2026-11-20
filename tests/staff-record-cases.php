<?php
declare(strict_types=1);

use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Services\PasswordPolicy;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

$profilePath = '/reports/staff-record';
$recordSecret = bin2hex(random_bytes(16));
$db->prepare("INSERT INTO users (role_id, department_id, name, email, password_hash, staff_id, created_at, must_change_password)
    SELECT id, ?, 'Cross-month staff', 'cross-month@example.test', ?, 'RECORDS-16', '2026-12-28 07:00:00', FALSE FROM roles WHERE code='staff'")
    ->execute([$deptId, PasswordPolicy::hash($recordSecret)]);
$periodStaff = (int) $db->lastInsertId();
$db->prepare('INSERT INTO supervisor_assignments (staff_id,supervisor_id,assigned_by) VALUES (?,?,?)')
    ->execute([$periodStaff, $otherReviewerId, $managerId]);
$schedule = $db->prepare('INSERT INTO staff_work_schedules (user_id,assignment_key,day_of_week,effective_from,effective_to,created_by)
    VALUES (?,?,?,?,?,?)');
foreach ([['2026-12-28','2026-12-31',[1,3,4]], ['2027-01-01',null,[2,5,7]]] as [$start, $end, $days]) {
    $key = bin2hex(random_bytes(16));
    foreach ($days as $day) {
        $schedule->execute([$periodStaff,$key,$day,$start,$end,$managerId]);
    }
}
$crossLeave = $db->prepare("INSERT INTO staff_leaves (user_id,leave_type,start_date,end_date,reason,status,approved_by,approved_at,created_by)
    VALUES (?,'sick','2026-12-30','2027-01-01','Confidential fixture leave reason','approved',?,CURRENT_TIMESTAMP,?)");
$crossLeave->execute([$periodStaff,$managerId,$managerId]);

$addReport = $db->prepare("INSERT INTO daily_reports (staff_id,department_id,report_date,status,content,reference_number,submitted_at,first_submitted_at)
    VALUES (?,?,?,'submitted','Unsubmitted change must stay private',?,?,?)");
$addRevision = $db->prepare("INSERT INTO report_revisions (report_id,revision_number,revised_by,content,snapshot,submitted_at,working_minutes)
    VALUES (?,1,?,?,?,?,450)");
$recordDecision = $db->prepare('INSERT INTO report_approvals (report_id,revision_number,reviewer_id,stage,decision,comments)
    VALUES (?,1,?,?,?,?)');
$reportIds = [];
foreach ([['2026-12-28','2026-12-28 18:15:00','DTR-RECORD-DEC28','Completed December work'],
    ['2026-12-31','2027-01-02 12:00:00','DTR-RECORD-DEC31','=2+2'],
    ['2027-01-03','2027-01-03 18:15:00','DTR-RECORD-JAN03','January report returned']] as [$day,$submitted,$ref,$work]) {
    $addReport->execute([$periodStaff,$deptId,$day,$ref,$submitted,$submitted]);
    $id = (int) $db->lastInsertId();
    $reportIds[$day] = $id;
    $snapshot = json_encode(['content'=>$work,'achievement'=>'Recorded milestones','challenges'=>'None',
        'arrival_time'=>'08:00:00','work_start_time'=>'08:30:00','work_end_time'=>'16:00:00','working_minutes'=>450]);
    $addRevision->execute([$id,$periodStaff,$work,$snapshot,$submitted]);
    if ($day === '2026-12-31') {
        $recordDecision->execute([$id,$otherReviewerId,'supervisor','approved','December supervisor decision']);
        $db->exec("UPDATE daily_reports SET status='pending_hr' WHERE id=$id");
        $recordDecision->execute([$id,$managerId,'hr','approved','December HR decision']);
        $db->exec("UPDATE daily_reports SET status='hr_approved' WHERE id=$id");
    } elseif ($day === '2027-01-03') {
        $recordDecision->execute([$id,$otherReviewerId,'supervisor','returned','January correction needed']);
        $db->exec("UPDATE daily_reports SET status='returned_by_supervisor' WHERE id=$id");
    }
}

$recordMonitor = new SubmissionMonitoring($db, new StaffWorkSchedules($db), '18:00');
$asOfJan = new DateTimeImmutable('2027-02-01 12:00:00');
$recordTotals = static function (string $from, string $to) use ($recordMonitor, $asOfJan, $periodStaff): array {
    $row = $recordMonitor->dashboard(['staff'=>$periodStaff], new DateTimeImmutable($from), new DateTimeImmutable($to), $asOfJan)['rows'][0];
    return array_map(static fn (string $key): int => (int) $row[$key], ['expected','actual','submitted','outstanding','approved','exempt_days']);
};
expect($recordTotals('2026-12-28','2027-01-03') === [2,3,2,0,1,3], 'cross-month ISO week includes both schedules and retains reports on leave dates');
expect($recordTotals('2026-12-01','2026-12-31') === [1,2,1,0,1,2], 'December includes its report submitted in January');
expect($recordTotals('2027-01-01','2027-01-31') === [13,1,1,12,0,1], 'January counts only its own scheduled days and report dates');

$self = new TestBrowser($argv[1]);
$loginWith($self,'cross-month@example.test',$recordSecret,303,'/');
$weekSelection = http_build_query(['staff'=>$periodStaff,'period'=>'week','week'=>'2026-W53']);
$monthSelection = http_build_query(['staff'=>$periodStaff,'period'=>'month','month'=>'2026-12']);
$hrProfile = $hr->request($profilePath . '?' . $weekSelection, 200);
expect($hrProfile->contains('Staff reporting profile') && $hrProfile->contains('Assigned supervisor')
    && $hrProfile->contains('Current schedule') && $hrProfile->contains('Current leave') && $hrProfile->contains('Account status')
    && $hrProfile->contains('28 Dec 2026 – 03 Jan 2027'), 'dedicated profile gives identity, period and current situation');
expect($hrProfile->contains('DTR-RECORD-DEC31') && $hrProfile->contains('DTR-RECORD-JAN03') && $hrProfile->contains('Supervisor')
    && $hrProfile->contains('HR') && $hrProfile->contains('7 h 30 min'), 'weekly profile displays cross-boundary reports, decisions and attendance');
$hrMonth = $hr->request($profilePath . '?' . $monthSelection, 200);
$hrRecordToken = $hrMonth->csrf();
expect($hrMonth->contains('DTR-RECORD-DEC31') && !$hrMonth->contains('DTR-RECORD-JAN03'), 'December profile is scoped by report date');
expect($self->request($profilePath, 200)->contains('Cross-month staff'), 'staff opens own profile without a staff ID query');
expect($portal->request($profilePath, 200)->contains('Approved through'), 'current approved leave is shown on the staff profile');
$self->request($profilePath . '?staff=' . $owner, 404);
$otherPortal->request($profilePath . '?' . $monthSelection, 404);
expect($anotherReviewer->request($profilePath . '?' . $weekSelection, 200)->contains('DTR-RECORD-DEC31'), 'current supervisor can view assigned staff');
expect(!$anotherReviewer->contains('Sick leave') && !$anotherReviewer->contains('Confidential fixture leave reason'), 'supervisor sees exemption dates with no private leave type or reason');
$authorizedReviewer->request($profilePath . '?' . $monthSelection, 404);
$adminBrowser->request($profilePath . '?' . $monthSelection, 403);
$officerBrowser->request($profilePath . '?' . $monthSelection, 403);
$hr->request($profilePath . '?staff=' . $periodStaff . '&period=month&month=2026-13', 422);

$exportRoute = $profilePath . '/export';
$exportPost = ['staff'=>$periodStaff,'period'=>'week','week'=>'2026-W53','format'=>'pdf'];
$hr->request($exportRoute, 403, $exportPost);
$otherPortal->request($exportRoute, 404, $exportPost + ['csrf'=>$otherPortal->request('/', 200)->csrf()]);
$authorizedReviewer->request($exportRoute, 404, $exportPost + ['csrf'=>$authorizedReviewer->request('/', 200)->csrf()]);
$adminBrowser->request($exportRoute, 403, $exportPost + ['csrf'=>$adminBrowser->request('/', 200)->csrf()]);
if (class_exists(Xlsx::class) && class_exists(\Dompdf\Dompdf::class)) {
    $before = (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event='staff_period_exported' AND object_id=$periodStaff")->fetchColumn();
    foreach (['week','month'] as $periodMode) {
        foreach (['pdf','xlsx'] as $format) {
            $payload = array_replace($exportPost, ['period'=>$periodMode,'format'=>$format,'month'=>'2026-12']);
            $response = $hr->request($exportRoute, 200, $payload + ['csrf'=>$hrRecordToken]);
            expect(str_contains($response->headers(), "attachment; filename=\"staff-$periodMode-"), 'period export has appropriate download filename');
            if ($format === 'pdf') {
                expect(str_starts_with($response->body(), '%PDF-') && str_contains($response->body(), '%%EOF'), 'Dompdf produces a complete period PDF');
                $artifacts = getenv('DTRMS_RECORD_ARTIFACTS');
                if (is_string($artifacts) && is_dir($artifacts)) {
                    file_put_contents($artifacts . '/' . $periodMode . '.pdf', $response->body());
                }
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'period-xlsx-');
            try {
                file_put_contents($path, $response->body());
                $book = (new Xlsx())->load($path);
                $daily = $book->getSheetByName('Daily reports');
                $references = array_column($daily->rangeToArray('A5:A7'), 0);
                expect(in_array('DTR-RECORD-DEC31', $references, true) && in_array('DTR-RECORD-JAN03', $references, true) === ($periodMode === 'week'),
                    'Excel period matches report-date boundaries');
                $referenceRow = array_search('DTR-RECORD-DEC31', $references, true) + 5;
                $cell = $daily->getCell('K' . $referenceRow);
                expect($cell->getValue() === '=2+2' && $cell->getDataType() === DataType::TYPE_STRING, 'report export uses safe submitted snapshot, never the later edit');
                expect($book->getSheetByName('Approval history')->getCell('E5')->getValue() !== null
                    && $book->getSheetByName('Leave exemptions')->getCell('A5')->getValue() === '2026-12-30', 'Excel includes approval and leave records');
                $book->disconnectWorksheets();
            } finally {
                unlink($path);
            }
        }
    }
    $self->request($exportRoute, 200, $exportPost + ['csrf'=>$self->request($profilePath . '?' . $weekSelection, 200)->csrf()]);
    $anotherReviewer->request($exportRoute, 200, $exportPost + ['csrf'=>$anotherReviewer->request($profilePath . '?' . $weekSelection, 200)->csrf()]);
    expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event='staff_period_exported' AND object_id=$periodStaff")->fetchColumn() - $before === 6,
        'successful exports by the allowed roles are audited');
}
$revoke->execute(['hr_export_reports']);
$hr->request($profilePath . '?' . $monthSelection, 200);
expect(!$hr->contains('Export Excel'), 'missing explicit HR export permission hides downloads');
$hr->request($exportRoute, 403, $exportPost + ['csrf'=>$hr->csrf()]);
$restore->execute(['hr_export_reports']);

$db->prepare('UPDATE supervisor_assignments SET ended_at=CURRENT_TIMESTAMP WHERE current_staff_id=?')->execute([$periodStaff]);
$anotherReviewer->request($profilePath . '?' . $weekSelection, 404);
$anotherReviewer->request($exportRoute, 404, $exportPost + ['csrf'=>$anotherReviewer->request('/', 200)->csrf()]);

$db->prepare("UPDATE staff_leaves SET status='cancelled' WHERE user_id=? AND start_date='2026-12-30'")->execute([$periodStaff]);
expect($recordTotals('2026-12-28','2027-01-03') === [5,3,3,2,1,0],
    'cancelled leave restores due days across both historical schedule patterns');
echo "PASS: individual record profiles, cross-month weekly/monthly totals, schedule history, leave adjustments, four exports, scopes and export audits\n";
