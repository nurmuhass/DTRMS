<?php
declare(strict_types=1);

use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Models\WeeklySchedules;
use Dtrms\Services\PasswordPolicy;
use Dtrms\Services\WeeklyCompliance;
use Dtrms\Services\WeeklyResults;

$tz = new DateTimeZone('Africa/Lagos');
$todayFlex = new DateTimeImmutable('now',$tz);
$priorWeek = WeeklyCompliance::week($todayFlex)->modify('-7 days');
$futureWeek = WeeklyCompliance::week($todayFlex)->modify('+7 days');
$priorKey = $priorWeek->format('Y-m-d');
$db->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='flexible_schedule_start'")->execute([$priorKey]);
$flexPassword = bin2hex(random_bytes(16));
$db->prepare("INSERT INTO users (role_id,department_id,name,email,staff_id,password_hash,must_change_password,created_at)
    SELECT id,?,'Flexible staff fixture','flexible@example.test','FLEX-01',?,FALSE,? FROM roles WHERE code='staff'")
    ->execute([$deptId,PasswordPolicy::hash($flexPassword),WeeklyCompliance::utc($priorWeek->modify('-7 days'))]);
$flexStaff = (int)$db->lastInsertId();
$db->prepare('INSERT INTO supervisor_assignments (staff_id,supervisor_id,assigned_by) VALUES (?,?,?)')
    ->execute([$flexStaff,$otherReviewerId,$managerId]);
$selfFlex = new TestBrowser($argv[1]);
$loginWith($selfFlex,'flexible@example.test',$flexPassword,303,'/');
$flexPath = '/staff/schedule?week='.$futureWeek->format('o-\WW');
$flexToken = $selfFlex->request($flexPath,200)->csrf();
$futureDays = array_map(static fn(int $offset):string=>$futureWeek->modify("+$offset days")->format('Y-m-d'),[1,3,5]);
$form = ['csrf'=>$flexToken,'action'=>'submit','days'=>$futureDays];
$selfFlex->request($flexPath,422,array_replace($form,['days'=>array_slice($futureDays,0,2)]));
$selfFlex->request($flexPath,422,array_replace($form,['days'=>[$futureDays[0],$futureDays[0],$futureDays[2]]]));
$selfFlex->request($flexPath,422,array_replace($form,['days'=>[$futureDays[0],$futureDays[1],$futureWeek->modify('+7 days')->format('Y-m-d')]]));
$selfFlex->request($flexPath,303,$form);
expect($selfFlex->request($flexPath,200)->contains(implode(', ',$futureDays)),'HTTP confirms exactly three distinct dates');
$selfFlex->request($flexPath.'&staff='.$owner,404);
$selfFlex->request('/staff/reports/view?id='.$preserved,404);
$selfFlex->request('/hr-manager/weekly-schedules?staff='.$flexStaff,403);
$anotherReviewer->request('/hr-manager/weekly-schedules?staff='.$flexStaff,403);
$selfFlex->request('/staff/schedule?week='.$priorWeek->format('o-\WW'),409,$form);

$flexSchedules = new WeeklySchedules($db);
$priorDays = array_map(static fn(int $offset):string=>$priorWeek->modify("+$offset days")->format('Y-m-d'),[1,3,5]);
$flexSchedules->change($flexStaff,$flexStaff,$priorWeek,$priorDays,'submit','',false,$priorWeek->modify('-1 day'));
$flexSchedules->lockStarted($todayFlex);
expect($flexSchedules->get($flexStaff,$priorKey)['status']==='locked','worker locks started confirmed schedule');
$hrFlexPath = '/hr-manager/weekly-schedules?staff='.$flexStaff.'&week='.$priorWeek->format('o-\WW');
$hrFlexToken = $hr->request($hrFlexPath,200)->csrf();
$hr->request($hrFlexPath,422,['csrf'=>$hrFlexToken,'action'=>'correct','days'=>$priorDays,'reason'=>'']);
$hr->request($hrFlexPath,303,['csrf'=>$hrFlexToken,'action'=>'unlock','reason'=>'Review historical selection']);
$hr->request($hrFlexPath,303,['csrf'=>$hrFlexToken,'action'=>'correct','days'=>$priorDays,'reason'=>'Confirm selected dates after review']);
expect($hr->request($hrFlexPath,200)->contains('Confirm selected dates after review'),'HR correction reason and old dates are visible');
expect((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE event='weekly_schedule_corrected' AND subject_user_id=$flexStaff")->fetchColumn()===1,
    'corrections audited');

$addFlex = $db->prepare("INSERT INTO daily_reports(staff_id,department_id,report_date,status,content,first_submitted_at,submitted_at)
    VALUES(?,?,?,?,'Flexible report fixture',?,?)");
$earlyStamp=WeeklyCompliance::utc($todayFlex);
$addFlex->execute([$flexStaff,$deptId,$futureDays[0],'submitted',$earlyStamp,$earlyStamp]);
$replaceEarly=[$futureWeek->format('Y-m-d'),$futureDays[1],$futureDays[2]];
$selfFlex->request($flexPath,409,array_replace($form,['days'=>$replaceEarly]));
$selfFlex->request($flexPath,409,array_replace($form,['action'=>'draft']));
$stamp = WeeklyCompliance::utc($priorWeek->modify('+2 days')->setTime(15,0));
$addFlex->execute([$flexStaff,$deptId,$priorDays[0],'submitted',$stamp,$stamp]);
$addFlex->execute([$flexStaff,$deptId,$priorDays[1],'draft',null,null]);
$addFlex->execute([$flexStaff,$deptId,$priorWeek->modify('+2 days')->format('Y-m-d'),'returned_by_supervisor',$stamp,$stamp]);
$late = WeeklyCompliance::utc($priorWeek->modify('+7 days'));
$addFlex->execute([$flexStaff,$deptId,$priorDays[2],'returned_by_supervisor',$late,$late]);
$flexMonitor = new SubmissionMonitoring($db,new StaffWorkSchedules($db),'18:00');
$readWeek = static fn(DateTimeImmutable $now):array=>$flexMonitor->dashboard(['staff'=>$flexStaff],$priorWeek,$priorWeek->modify('+6 days'),$now)['rows'][0];
$before = $readWeek($priorWeek->modify('+2 days')->setTime(20,0));
expect((int)$before['expected']===3 && (int)$before['due_outstanding']===0 && (int)$before['submitted']===1,'future selected days are not overdue');
$after = $readWeek($todayFlex);
expect((int)$after['expected']===3 && (int)$after['submitted']===1 && (int)$after['outstanding']===2
    && (int)$after['returned']===2 && $after['compliance_status']==='missed',
    'draft, returned off-schedule and late report do not fill due dates; submitted pending review counts');
expect(!SubmissionMonitoring::isSubmitted('cancelled'),'cancelled reports excluded from submission states');
$deadline = WeeklyCompliance::deadline($priorWeek);
expect($deadline->format('l H:i:s T')==='Sunday 23:59:59 WAT'
    && WeeklyCompliance::utc($deadline) === $priorWeek->modify('+6 days')->format('Y-m-d').' 22:59:59','configured Lagos Sunday deadline');
(new WeeklyResults($db))->finalize($todayFlex);
$leaveSelect = $db->prepare("INSERT INTO staff_leaves(user_id,leave_type,start_date,end_date,reason,status,decision_note,created_by)
    VALUES(?,'annual',?,?,'Flexible fixture',?,?,?)");
foreach (['pending','rejected'] as $state) {
    $leaveSelect->execute([$flexStaff,$priorWeek->modify('+2 days')->format('Y-m-d'),$priorWeek->modify('+4 days')->format('Y-m-d'),
        $state,$state==='rejected'?'Fixture declined':null,$managerId]);
}
expect((int)$readWeek($todayFlex)['expected']===3,'pending and rejected leave do not exempt dates');
$pending = (int)$db->query("SELECT id FROM staff_leaves WHERE user_id=$flexStaff AND status='pending'")->fetchColumn();
$hrLeave = '/hr-manager/leaves/view?id='.$pending;
$token = $hr->request($hrLeave,200)->csrf();
$hr->request($hrLeave,303,['csrf'=>$token,'decision'=>'approve','version'=>'0']);
expect((int)$readWeek($todayFlex)['expected']===2 && $readWeek($todayFlex)['exemption_dates']===$priorDays[1],
    'approved leave exempts exactly the overlapping selected day');
$hr->request($hrLeave,303,['csrf'=>$token,'decision'=>'cancel','version'=>'1','decision_note'=>'Restore expectation for test']);
expect((int)$readWeek($todayFlex)['expected']===3,'cancelled leave restores expectation');
$history = $db->prepare("SELECT final_result,reason FROM staff_weekly_compliance_history WHERE user_id=? AND week_start=? ORDER BY id");
$history->execute([$flexStaff,$priorKey]);
$versions=$history->fetchAll();
expect(count($versions)>=3 && (int)json_decode($versions[array_key_last($versions)]['final_result'],true)['expected']===3,
    'late leave approval and cancellation preserve earlier finalized results');
$missing=$flexMonitor->dashboard(['staff'=>$owner],$priorWeek,$priorWeek->modify('+6 days'),$todayFlex)['rows'][0];
expect($missing['compliance_status']==='schedule_missing' && $missing['percentage']===null,'missing schedule distinct from missing reports');
$cross=$futureWeek->modify('+14 days');
while ($cross->format('Y-m')===$cross->modify('+6 days')->format('Y-m')) {
    $cross=$cross->modify('+7 days');
}
$crossDates=array_map(static fn(int $offset):string=>$cross->modify("+$offset days")->format('Y-m-d'),[0,3,6]);
$flexSchedules->change($flexStaff,$flexStaff,$cross,$crossDates,'submit','',false,$cross->modify('-1 day'));
$crossDate=$crossDates[0];
$crossStamp=WeeklyCompliance::utc($cross->modify('+6 days')->setTime(12,0));
$addFlex->execute([$flexStaff,$deptId,$crossDate,'submitted',$crossStamp,$crossStamp]);
$crossId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO report_revisions(report_id,revision_number,revised_by,content,submitted_at)
    VALUES(?,1,?,'On-time report',?)")->execute([$crossId,$flexStaff,$crossStamp]);
$db->prepare("INSERT INTO report_approvals(report_id,revision_number,reviewer_id,stage,decision)
    VALUES(?,1,?,'supervisor','approved')")->execute([$crossId,$otherReviewerId]);
$db->prepare("UPDATE daily_reports SET status='pending_hr' WHERE id=?")->execute([$crossId]);
$monthSplit=$flexMonitor->flexibleMonths([$flexStaff],$cross,$cross->modify('+6 days'),$cross->modify('+7 days'))[$flexStaff];
$firstMonth=$cross->format('Y-m');
$nextMonth=$cross->modify('+6 days')->format('Y-m');
expect((int)$monthSplit[$firstMonth]['expected']+(int)$monthSplit[$nextMonth]['expected']===3
    && (int)$monthSplit[$firstMonth]['fulfilled']===1 && (int)$monthSplit[$nextMonth]['fulfilled']===0,
    'a cross-month flexible week splits selected dates and counts a pending-HR report only in its reporting month');
$noticeRun = new \Dtrms\Services\WeeklyReminders($db,new \Dtrms\Models\Notifications($db));
$noticeAt=$todayFlex->setTime(19,0);
$noticePolicy=array_replace(\Dtrms\Services\OperationalPolicy::DEFAULTS,['reminders_enabled'=>true]);
expect($noticeRun->run($noticeAt,$noticePolicy)['queued']>0,'weekly worker queues deadline and schedule reminders');
expect($noticeRun->run($noticeAt,$noticePolicy)['queued']===0,'weekly reminders deduplicate on retry');
$event=$db->prepare('SELECT COUNT(*) FROM notification_events WHERE event_key=?');
$event->execute([hash('sha256',$flexStaff.':weekly_reports_overdue:0:'.$priorKey)]);
expect((int)$event->fetchColumn()===1,'weekly overdue notification queued once for a confirmed schedule');
$changedDays = [$priorKey,$priorDays[1],$priorDays[2]];
$hr->request($hrFlexPath,303,['csrf'=>$hrFlexToken,'action'=>'correct','days'=>$changedDays,'reason'=>'Authorized change after submission']);
$audit = $db->query("SELECT metadata FROM audit_logs WHERE event='weekly_schedule_corrected'
    AND subject_user_id=$flexStaff ORDER BY id DESC LIMIT 1")->fetchColumn();
$details=json_decode($audit,true,32,JSON_THROW_ON_ERROR);
expect($details['previous']['days']===$priorDays && $details['new']['days']===$changedDays
    && count($details['new']['affected_report_ids'])===1,'historical correction records removed dates and affected report ID');
if (class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class)) {
    $export=$hr->request('/reports/staff-record/export',200,[
        'csrf'=>$hrFlexToken,'staff'=>$flexStaff,'period'=>'week','week'=>$priorWeek->format('o-\WW'),'format'=>'xlsx',
    ])->body();
    $tempPath=tempnam(sys_get_temp_dir(),'dtrms-flex-');
    try {
        file_put_contents($tempPath,$export);
        $workbook=(new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($tempPath);
        $values=array_column(array_slice($workbook->getSheetByName('Summary')->toArray(),4),1,0);
        expect($values['Selected work dates in period']===implode(', ',$changedDays)
            && (int)$values['Expected submissions']===3 && (int)$values['Submitted for due dates']===0,
            'flexible schedule export uses corrected dates and cutoff-adjusted totals');
        $workbook->disconnectWorksheets();
    } finally {
        unlink($tempPath);
    }
}
echo "PASS: flexible dates, lock and audit, exact-date leave, Sunday cutoff, monthly attribution, finalized history, reminders and role boundaries\n";
