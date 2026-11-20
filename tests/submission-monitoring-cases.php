<?php
declare(strict_types=1);

use Dtrms\Models\Notifications;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Services\MonitoringFilters;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\ReportReminders;

$monitorPath = '/hr-manager/submissions';
$monitor = new SubmissionMonitoring($db, new StaffWorkSchedules($db), '18:00');
$week = $monday->format('o-\WW');
$weekEnd = $monday->modify('+6 days');
$closed = $monday->modify('+7 days')->setTime(19, 0);
$readStaff = static fn (int $id, DateTimeImmutable $asOf): array => $monitor->dashboard(['staff' => $id], $monday, $weekEnd, $asOf)['rows'][0];
$beforeCutoff = $readStaff($owner, $monday->setTime(17, 59));
expect($beforeCutoff['compliance_status'] === 'not_due' && (int) $beforeCutoff['expected'] === 0
    && (int) $beforeCutoff['outstanding'] === 0 && $beforeCutoff['percentage'] === null, 'future scheduled dates never count overdue');
$atCutoff = $readStaff($owner, $monday->setTime(18, 0));
expect((int) $atCutoff['expected'] === 1 && (int) $atCutoff['submitted'] === 1, 'today becomes due at the exact deadline');
$ownerClosed = $readStaff($owner, $closed);
expect($ownerClosed['compliance_status'] === 'complete' && (int) $ownerClosed['expected'] === 1
    && (int) $ownerClosed['approved'] === 0 && (float) $ownerClosed['percentage'] === 100.0, 'submitted pending approval satisfies a due date; leave is excluded');
$otherClosed = $readStaff($otherId, $closed);
expect($otherClosed['compliance_status'] === 'none' && (int) $otherClosed['expected'] === 2 && (int) $otherClosed['outstanding'] === 2,
    'staff with a different schedule and no submissions show outstanding due dates');
$inProgress = $readStaff($otherId, $monday->modify('+2 days')->setTime(17, 0));
expect($inProgress['compliance_status'] === 'progress' && (int) $inProgress['outstanding'] === 1
    && (int) $inProgress['future_days'] === 1, 'open period distinguishes already due work from future work');

$fixtureReport = $db->prepare('INSERT INTO daily_reports (staff_id, department_id, report_date, status, content, submitted_at)
    VALUES (?, ?, ?, ?, ?, ?)');
$fixtureReport->execute([$otherId, $deptId, $monday->modify('+1 day')->format('Y-m-d'), 'draft', 'Monitoring draft', null]);
$draftId = (int) $db->lastInsertId();
$fixtureReport->execute([$otherId, $deptId, $monday->modify('+3 days')->format('Y-m-d'), 'returned_by_supervisor',
    'Submitted on approved leave', gmdate('Y-m-d H:i:s')]);
$fixtureReport->execute([$otherId, $deptId, $monday->modify('+5 days')->format('Y-m-d'), 'returned_by_supervisor',
    'Requires correction', gmdate('Y-m-d H:i:s')]);
$returnedId = (int) $db->lastInsertId();
$partial = $readStaff($otherId, $closed);
expect($partial['compliance_status'] === 'incomplete' && (int) $partial['actual'] === 2 && (int) $partial['submitted'] === 1
    && (int) $partial['outstanding'] === 1 && (int) $partial['returned'] === 2 && (float) $partial['percentage'] === 50.0,
    'drafts excluded, returned submissions retained, off-schedule and exempt dates cannot fill a missing due date');
expect(!$monitor->isSubmitted('cancelled') && !$monitor->isSubmitted('draft'), 'cancelled and draft states excluded by explicit submission policy');

$query = http_build_query(['period' => 'week', 'week' => $week, 'q' => 'PORTAL-2', 'department' => $deptId, 'leave' => 'yes']);
$page = $hr->request($monitorPath . '?' . $query, 200);
expect($page->contains('Staff Submission Monitoring') && $page->contains('>Second Portal Staff</a>') && !$page->contains('>Portal Test Staff</a>'),
    'HR monitor links, period scope and staff search render');
$hr->request($monitorPath . '?period=month&month=' . $monday->format('Y-m'), 200);
$hr->request($monitorPath . '?period=week&week=2026-W99', 422);
$hr->request($monitorPath . '?period=month&month=2026-13', 422);
$portal->request($monitorPath, 403);
$officerBrowser->request($monitorPath, 403);
$adminBrowser->request($monitorPath, 403);
$personUrl = $monitorPath . '/staff?staff=' . $otherId . '&week=' . $week;
$personPage = $hr->request($personUrl, 200);
expect($personPage->contains('Weekly submission history') && $personPage->contains('Monthly submission history')
    && $personPage->contains('Approval history') && $personPage->contains('Recent leave history')
    && $personPage->contains('staff-entered attendance'), 'staff drilldown includes history, approvals, attendance and leave');
$hr->request($monitorPath . '/staff?staff=' . $managerId, 404);
$reportPath = $monitorPath . '/report?staff=' . $otherId . '&id=' . $returnedId;
expect($hr->request($reportPath, 200)->contains('Print report') && $hr->contains('Requires correction'), 'returned report is openable and printable');
$hr->request($monitorPath . '/report?staff=' . $owner . '&id=' . $returnedId, 404);
$hr->request($monitorPath . '/report?staff=' . $otherId . '&id=' . $draftId, 404);
$portal->request($reportPath, 403);
$revoke->execute(['hr_review_reports']);
$hr->request($monitorPath, 200);
$hr->request($reportPath, 403);
$restore->execute(['hr_review_reports']);

$holidayPath = $monitorPath . '/holidays';
$holidayToken = $hr->request($holidayPath, 200)->csrf();
$departmentHoliday = ['csrf' => $holidayToken, 'action' => 'add', 'date' => $monday->modify('+1 day')->format('Y-m-d'),
    'name' => 'Department holiday', 'department' => $deptId];
$hr->request($holidayPath, 403, array_replace($departmentHoliday, ['csrf' => 'invalid']));
$hr->request($holidayPath, 303, array_replace($departmentHoliday, ['department' => $otherDeptId]));
expect((int) $readStaff($otherId, $closed)['expected'] === 2, 'other department holiday has no effect');
$hr->request($holidayPath, 303, $departmentHoliday);
$hr->request($holidayPath, 422, $departmentHoliday);
expect((int) $readStaff($otherId, $closed)['expected'] === 1 && $readStaff($otherId, $closed)['compliance_status'] === 'complete',
    'matching department holiday exempts its workday');
$holidayId = (int) $db->query("SELECT MAX(id) FROM work_holidays WHERE department_id=$deptId")->fetchColumn();
$hr->request($holidayPath, 303, ['csrf' => $holidayToken, 'action' => 'remove', 'id' => $holidayId]);
expect((int) $readStaff($otherId, $closed)['expected'] === 2, 'holiday removal restores due dates');
$nextTuesday = $monday->modify('+8 days');
$hr->request($holidayPath, 303, array_replace($departmentHoliday, ['date' => $nextTuesday->format('Y-m-d'), 'department' => '']));
(new ReportReminders($db, new Notifications($db)))->schedule($nextTuesday->setTime(19, 0),
    array_replace(OperationalPolicy::DEFAULTS, ['reminders_enabled' => true]));
$event = $db->prepare('SELECT COUNT(*) FROM notification_events WHERE event_key = ?');
$event->execute([hash('sha256', $owner . ':reminder_missing_report:0:' . $nextTuesday->format('Y-m-d'))]);
expect((int) $event->fetchColumn() === 0, 'organization-wide holiday stops a scheduled missing-report reminder');

$leaveFixture = $db->prepare("INSERT INTO staff_leaves
    (user_id,leave_type,start_date,end_date,reason,status,approved_by,approved_at,created_by)
    VALUES (?,'annual',?,?,'Monitoring fixture','approved',?,CURRENT_TIMESTAMP,?)");
$nextWeek = $monday->modify('+7 days');
$nextEnd = $nextWeek->modify('+6 days');
$leaveFixture->execute([$otherId, $nextWeek->format('Y-m-d'), $nextEnd->format('Y-m-d'), $managerId, $managerId]);
$leaveRow = $monitor->dashboard(['staff' => $otherId], $nextWeek, $nextEnd, $nextEnd->modify('+1 day'))['rows'][0];
expect($leaveRow['compliance_status'] === 'leave' && (int) $leaveRow['leave_days'] === 7
    && (int) $leaveRow['expected'] === 0 && $leaveRow['percentage'] === null, 'fully exempt week shows On Leave without division');
$filtered = $monitor->dashboard(['q' => 'PORTAL-2', 'department' => $deptId, 'leave' => 'yes', 'status' => 'incomplete'],
    $monday, $weekEnd, $closed);
expect($filtered['count'] === 1 && (int) $filtered['summary']['outstanding_total'] === 1, 'status and leave filters apply to aggregated totals');
expect(MonitoringFilters::period(['period' => 'week', 'week' => '2026-W01'], $closed)['from']->format('Y-m-d') === '2025-12-29',
    'ISO weeks cross the year boundary');
echo "PASS: weekly/monthly compliance, deadlines, leave, holiday scope, returned/draft/cancelled policy, monitoring permissions, and report drilldown\n";
