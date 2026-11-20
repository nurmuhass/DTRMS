<?php
declare(strict_types=1);

use Dtrms\Models\ReportAnalytics;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Models\WorkHolidays;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\ReportFilters;
use Dtrms\Services\ReportingSummary;

$matrixFrom = new DateTimeImmutable('2027-04-26');
$matrixTo = $matrixFrom->modify('+6 days');
$matrixNow = $matrixTo->modify('+1 day')->setTime(19, 0);
$mon = '2027-04-26';
$wed = '2027-04-28';
$fri = '2027-04-30';
$sat = '2027-05-01';
$sun = '2027-05-02';
$monitor = new SubmissionMonitoring($db, new StaffWorkSchedules($db), '18:00');
$analytics = new ReportingSummary(new ReportAnalytics($db), OperationalPolicy::DEFAULTS,
    new StaffWorkSchedules($db), new StaffLeaves($db), new WorkHolidays($db));
$workload = [
    [1,'Three submissions',[$mon,$wed,$fri],null,[3,3,0,100.0,0,'complete']],
    [2,'Two submissions',[$mon,$wed],null,[3,2,1,66.7,0,'incomplete']],
    [3,'One submission',[$mon],null,[3,1,2,33.3,0,'incomplete']],
    [4,'Zero submissions',[],null,[3,0,3,0.0,0,'none']],
    [5,'Full approved leave',[],['approved',$mon,$sun],[0,0,0,null,3,'leave']],
    [6,'Partial approved leave',[$mon,$fri],['approved',$wed,$wed],[2,2,0,100.0,1,'complete']],
    [7,'Pending leave',[$mon],['pending',$mon,$sun],[3,1,2,33.3,0,'incomplete']],
    [8,'Rejected leave',[$mon],['rejected',$mon,$sun],[3,1,2,33.3,0,'incomplete']],
    [9,'Schedule change',[$mon,$sat],null,[4,2,2,50.0,0,'incomplete']],
    [10,'Scheduled holiday',[$mon,$wed],null,[2,2,0,100.0,0,'complete']],
    [11,'Awaiting supervisor approval',[$mon],null,[3,1,2,33.3,0,'incomplete']],
    [12,'Awaiting HR approval',[$mon],null,[3,1,2,33.3,0,'incomplete']],
    [13,'Returned report',[$mon],null,[3,1,2,33.3,0,'incomplete']],
    [14,'Draft only',[$mon],null,[3,0,3,0.0,0,'none']],
    [15,'Week spans two months',[$mon,$sat],null,[3,2,1,66.7,0,'incomplete']],
    [16,'Deactivated mid-period',[$mon],null,[2,1,1,50.0,0,'incomplete']],
];
$userInsert = $db->prepare("INSERT INTO users (role_id,department_id,name,email,staff_id,password_hash,created_at)
    SELECT id,?,?,?,?,?,'2027-04-01 07:00:00' FROM roles WHERE code='staff'");
$assignmentInsert = $db->prepare('INSERT INTO supervisor_assignments (staff_id,supervisor_id,assigned_by) VALUES (?,?,?)');
$scheduleInsert = $db->prepare('INSERT INTO staff_work_schedules (user_id,assignment_key,day_of_week,effective_from,effective_to,created_by)
    VALUES (?,?,?,?,?,?)');
$leaveInsert = $db->prepare("INSERT INTO staff_leaves (user_id,leave_type,start_date,end_date,reason,status,approved_by,approved_at,created_by)
    VALUES (?,'annual',?,?,'Compliance test fixture',?,?,?,?)");
$reportInsert = $db->prepare("INSERT INTO daily_reports (staff_id,department_id,report_date,status,content,first_submitted_at,submitted_at)
    VALUES (?,?,?,?,'Compliance test fixture',?,?)");
$revisionInsert = $db->prepare("INSERT INTO report_revisions (report_id,revision_number,revised_by,content,submitted_at)
    VALUES (?,1,?,'Compliance test fixture',?)");
$approvalInsert = $db->prepare("INSERT INTO report_approvals (report_id,revision_number,reviewer_id,stage,decision)
    VALUES (?,1,?,'supervisor',?)");
$db->prepare("INSERT INTO work_holidays (holiday_date,name,department_id,created_by) VALUES (?,'Matrix holiday',?,?)")
    ->execute([$fri,$otherDeptId,$managerId]);
$testRows = [];
$failures = [];
foreach ($workload as [$number,$label,$submissions,$leave,$expected]) {
    $department = $number === 10 ? $otherDeptId : $deptId;
    $userInsert->execute([$department,"Compliance case $number","compliance-$number@example.test","COMPLIANCE-$number",'unused-test-password']);
    $id = (int) $db->lastInsertId();
    $assignmentInsert->execute([$id,$otherReviewerId,$managerId]);
    $schedules = match ($number) {
        9 => [[$mon,'2027-04-28',[1,3,5]], ['2027-04-29',null,[2,4,6]]],
        15 => [[$mon,null,[1,3,6]]],
        default => [[$mon,null,[1,3,5]]],
    };
    foreach ($schedules as [$from,$until,$weekdays]) {
        $key = bin2hex(random_bytes(16));
        foreach ($weekdays as $weekday) {
            $scheduleInsert->execute([$id,$key,$weekday,$from,$until,$managerId]);
        }
    }
    if ($leave !== null) {
        [$status,$start,$end] = $leave;
        $leaveInsert->execute([$id,$start,$end,$status,$status === 'approved' ? $managerId : null,
            $status === 'approved' ? '2027-04-25 12:00:00' : null,$managerId]);
    }
    foreach ($submissions as $date) {
        $stamp = $number === 14 ? null : (($number === 15 ? '2027-05-02' : $date) . ' 16:00:00');
        $reportInsert->execute([$id,$department,$date,$number === 14 ? 'draft' : 'submitted',$stamp,$stamp]);
        $reportId = (int) $db->lastInsertId();
        if (in_array($number,[12,13],true)) {
            $revisionInsert->execute([$reportId,$id,$stamp]);
            $approvalInsert->execute([$reportId,$otherReviewerId,$number === 12 ? 'approved' : 'returned']);
            $db->prepare('UPDATE daily_reports SET status=? WHERE id=?')
                ->execute([$number === 12 ? 'pending_hr' : 'returned_by_supervisor',$reportId]);
        }
    }
    if ($number === 16) {
        $db->exec("SET timestamp = UNIX_TIMESTAMP('2027-04-29 16:00:00')");
        try {
            $db->prepare('UPDATE users SET is_active=FALSE WHERE id=?')->execute([$id]);
        } finally {
            $db->exec('SET timestamp = 0');
        }
    }
    $record = $monitor->dashboard(['staff'=>$id],$matrixFrom,$matrixTo,$matrixNow)['rows'][0];
    $actual = [(int)$record['expected'],(int)$record['actual'],(int)$record['outstanding'],
        $record['percentage'] === null ? null : (float)$record['percentage'],(int)$record['exempt_days'],$record['compliance_status']];
    $matches = $actual === $expected;
    $testRows[] = ['case'=>$number,'scenario'=>$label,'expected'=>$expected,'actual'=>$actual,'passed'=>$matches];
    if (!$matches) {
        $failures[] = $number;
    }
    if ($number === 9) {
        $early = $monitor->dashboard(['staff'=>$id],$matrixFrom,$matrixFrom->modify('+2 days'),$matrixNow)['rows'][0];
        expect((int)$early['expected'] === 2, 'future schedule cannot change dates under the prior weekly pattern');
    }
    if ($number === 15) {
        $april = $monitor->dashboard(['staff'=>$id],$matrixFrom->modify('first day of this month'),
            $matrixFrom->modify('last day of this month'),$matrixNow)['rows'][0];
        $may = $monitor->reports($id,'2027-05-01','2027-05-31');
        expect((int)$april['actual'] === 1 && count($may) === 1 && $may[0]['report_date'] === $sat,
            'monthly membership is based on reporting date even when submission timestamp crosses the boundary');
    }
    if ($number === 16) {
        $search = $monitor->dashboard(['q'=>'COMPLIANCE-16'],$matrixFrom,$matrixTo,$matrixNow);
        expect($search['count'] === 1 && (int)$search['summary']['active_count'] === 0, 'historical inactive row stays visible without inflating active headcount');
        $monthFilter = ReportFilters::parse(['from'=>$mon,'until'=>$sun,'staff'=>(string)$id],$matrixNow);
        $monthly = $analytics->build('organization',$managerId,$monthFilter,$matrixNow);
        expect(array_sum(array_column($monthly['departments'],'expected')) === 2
            && array_sum(array_column($monthly['departments'],'missing')) === 1, 'departmental reporting uses the same deactivation cutoff');
        $event = $db->prepare('SELECT is_active,changed_at FROM user_account_status_history WHERE user_id=? ORDER BY id DESC LIMIT 1');
        $event->execute([$id]);
        $status = $event->fetch();
        expect($status && !(bool)$status['is_active'] && str_starts_with($status['changed_at'],'2027-04-29 16:00:00'),
            'account update records effective UTC history');
        $db->exec("SET timestamp = UNIX_TIMESTAMP('2027-05-04 10:00:00')");
        try {
            $db->prepare('UPDATE users SET is_active=TRUE WHERE id=?')->execute([$id]);
        } finally {
            $db->exec('SET timestamp = 0');
        }
        $afterReactivation = $monitor->dashboard(['staff'=>$id],$matrixFrom,$matrixTo,$matrixNow)['rows'][0];
        expect((int)$afterReactivation['expected'] === 2, 'reactivation cannot rewrite an earlier inactive interval');
    }
}
foreach ($testRows as $row) {
    echo 'COMPLIANCE ' . json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . PHP_EOL;
}
expect($failures === [], 'matrix failures: ' . implode(',', $failures));
echo "PASS: all 16 compliance and leave scenarios\n";
