<?php
$base = \Dtrms\Controllers\StaffRecordsController::PATH;
$period = $data['period'];
$selection = ['period' => $period['mode'], 'week' => $period['week'], 'month' => $period['month']];
$staff = $data['staff'];
$staffId = (int) $staff['id'];
$current = $data['current'];
$back = match ($data['scope']) {
    'staff' => ['/staff/reports', 'Back to my reports'],
    'supervisor' => ['/supervisor/staff', 'Back to assigned staff'],
    'hr' => [\Dtrms\Controllers\SubmissionMonitoringController::PATH . '?' . http_build_query($selection), 'Back to submission monitoring'],
};
?>
<div class="hr-heading"><h1><?= e($staff['name']) ?> · Staff reporting profile</h1><a href="<?= e($back[0]) ?>"><?= e($back[1]) ?></a></div>
<section class="card staff-panel" aria-label="Current staff profile">
    <dl class="report-facts">
        <div><dt>Staff ID</dt><dd><?= e($staff['staff_id'] ?? '—') ?></dd></div>
        <div><dt>Department</dt><dd><?= e($staff['department'] ?? '—') ?></dd></div>
        <div><dt>Assigned supervisor</dt><dd><?= e($staff['supervisor'] ?? 'Unassigned') ?></dd></div>
        <div><dt>Account status</dt><dd><?= $staff['is_active'] ? 'Active' : 'Inactive' ?></dd></div>
        <div><dt>Current schedule</dt><dd><?= e($staff['schedule']) ?></dd></div>
        <div><dt>Current leave</dt><dd><?= $staff['leave_until'] ? e('Approved through ' . $staff['leave_until']) : 'No approved leave today' ?></dd></div>
    </dl>
</section>
<div class="staff-dashboard-grid staff-panel">
    <form method="get" action="<?= e($base) ?>" class="card hr-filters">
        <input type="hidden" name="staff" value="<?= $staffId ?>"><input type="hidden" name="period" value="week">
        <div class="form-field hr-search"><label for="staff-record-week">Reporting week</label>
            <input id="staff-record-week" type="week" name="week" value="<?= e($period['week']) ?>" required></div>
        <button class="button button-secondary">View week</button>
    </form>
    <form method="get" action="<?= e($base) ?>" class="card hr-filters">
        <input type="hidden" name="staff" value="<?= $staffId ?>"><input type="hidden" name="period" value="month">
        <div class="form-field hr-search"><label for="staff-record-month">Reporting month and year</label>
            <input id="staff-record-month" type="month" name="month" value="<?= e($period['month']) ?>" required></div>
        <button class="button button-secondary">View month</button>
    </form>
</div>
<div class="report-heading">
    <div><h2><?= $period['mode'] === 'week' ? 'Weekly record' : 'Monthly record' ?></h2>
        <p class="intro"><?= e($period['from']->format('d M Y') . ' – ' . $period['until']->format('d M Y')) ?> · <?= e($data['states'][$current['compliance_status']]) ?></p></div>
    <?php if ($data['canExport']): ?>
        <form method="post" action="<?= e($base) ?>/export" class="report-actions" data-download>
            <?php require $directory . '/auth/csrf.php'; ?>
            <input type="hidden" name="staff" value="<?= $staffId ?>">
            <?php foreach ($selection as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
            <button class="button button-secondary" name="format" value="pdf">Export PDF</button>
            <button class="button button-secondary" name="format" value="xlsx">Export Excel</button>
        </form>
    <?php endif; ?>
</div>
<section class="staff-stats" aria-label="Selected period compliance">
    <?php foreach (['expected' => 'Expected', 'actual' => 'Submitted', 'submitted' => 'Submitted for due dates', 'outstanding' => 'Outstanding',
        'approved' => 'HR approved', 'exempt_days' => 'Approved leave exemptions', 'percentage' => 'Compliance'] as $key => $label): ?>
        <div class="card staff-stat"><span><?= e($label) ?></span><strong><?= $key === 'percentage'
            ? ($current[$key] === null ? 'N/A' : e((string) $current[$key]) . '%') : (int) $current[$key] ?></strong></div>
    <?php endforeach; ?>
</section>
<?php if ($current['flexible']): ?>
    <p class="intro staff-panel">Selected dates <?= e($current['selected_dates'] ?? 'Schedule Not Submitted') ?> ·
        <?= (int)$current['due_outstanding'] ?> already due<?php if ($current['missing_weeks']): ?> · Schedule Not Submitted for <?= (int)$current['missing_weeks'] ?> week(s)<?php endif; ?>.</p>
<?php endif; ?>
<?php if ($data['finalized']): ?>
    <section class="card staff-panel">
        <?php $table = ['caption'=>'Finalized weekly results',
            'headers'=>['Week starting','Expected','Submitted','Outstanding','Recorded at','Actor','Reason'],
            'rows'=>array_map(static function(array $row):array {
                $result=json_decode($row['final_result'],true,16,JSON_THROW_ON_ERROR);
                return [$row['week_start'],$result['expected'],$result['submitted'],$result['outstanding'],
                    report_timestamp($row['created_at']),$row['name'] ?? 'Scheduled worker',$row['reason']];
            },$data['finalized'])];
        require $directory . '/components/table.php'; ?>
    </section>
<?php endif; ?>
<?php foreach (['weeks' => ['Weekly submission history', 'Week starting'], 'months' => ['Monthly submission history', 'Month starting']] as $key => [$title, $heading]): ?>
    <section class="card staff-panel">
        <?php $table = ['caption' => $title, 'headers' => [$heading, 'Expected', 'Submitted', 'Submitted for due days', 'Outstanding', 'Compliance'],
            'rows' => array_map(static fn (array $row): array => [$row['period'], $row['expected'], $row['actual'], $row['submitted'],
                $row['outstanding'], $row['percentage'] === null ? '—' : $row['percentage'] . '%'], $data[$key])];
        require $directory . '/components/table.php'; ?>
    </section>
<?php endforeach; ?>
<section class="card staff-panel">
    <h2>Daily submissions and staff-entered attendance</h2>
    <p class="intro"><?= (int) $data['attendance']['count'] ?> reports with recorded duration ·
        <?= e(recorded_work_duration((int) $data['attendance']['minutes'])) ?> recorded</p>
    <div class="table-scroll" role="region" tabindex="0" aria-label="Daily submissions and attendance">
        <table class="hr-table">
            <thead><tr><th scope="col">Reporting date</th><th scope="col">Report</th><th scope="col">Last submitted</th>
                <th scope="col">Department</th><th scope="col">Approval status</th>
                <th scope="col">Arrival</th><th scope="col">Started</th><th scope="col">Ended</th><th scope="col">Duration</th></tr></thead>
            <tbody>
                <?php foreach ($data['reports'] as $report): ?>
                    <tr><td><?= e($report['report_date']) ?></td><th scope="row">
                        <?php if ($data['dailyLink'] !== null): ?><a href="<?= e($data['dailyLink'] . (int) $report['id']) ?>"><?= e(report_reference($report)) ?></a>
                        <?php else: ?><?= e(report_reference($report)) ?><?php endif; ?>
                    </th><td><?= e(report_timestamp($report['submitted_at'])) ?></td>
                    <td><?= e($report['department'] ?? '—') ?></td><td><?php $status = $report['status']; require $directory . '/staff/status.php'; ?></td>
                    <td><?= e(report_clock($report['arrival_time'])) ?></td><td><?= e(report_clock($report['work_start_time'])) ?></td>
                    <td><?= e(report_clock($report['work_end_time'])) ?></td><td><?= $report['working_minutes'] === null
                        ? '—' : e(recorded_work_duration((int) $report['working_minutes'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$data['reports']): ?><tr><td colspan="9">No daily submissions in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="intro">Returned reports remain submitted and require correction. Attendance times are entered by staff.</p>
</section>
<section class="card staff-panel">
    <?php $table = ['caption' => 'Approval history', 'headers' => ['Report', 'Revision', 'Stage', 'Decision', 'Reviewer', 'Recorded at'],
        'rows' => array_map(static fn (array $item): array => [report_reference($item), $item['revision_number'], $item['stage'] === 'hr' ? 'HR' : 'Supervisor',
            ucfirst($item['decision']), $item['reviewer'], report_timestamp($item['created_at'])], $data['approvals'])];
    require $directory . '/components/table.php'; ?>
    <?php if (!$data['approvals']): ?><p class="empty-state">No approval decisions in this period.</p><?php endif; ?>
</section>
<section class="card staff-panel">
    <h2>Scheduled dates exempted by approved leave</h2>
    <p><?= $data['exemptions'] === [] ? 'None in the selected period.' : e(implode(', ', $data['exemptions'])) ?></p>
    <?php $table = ['caption' => 'Recent leave history',
        'headers' => $data['scope'] === 'supervisor' ? ['Start', 'End', 'Status'] : ['Type', 'Start', 'End', 'Status'],
        'rows' => array_map(static fn (array $item): array => [
            ...(isset($item['leave_type']) ? [\Dtrms\Models\StaffLeaves::TYPES[$item['leave_type']]] : []),
            $item['start_date'], $item['end_date'], \Dtrms\Models\StaffLeaves::STATUSES[$item['status']]], $data['leaves'])];
    require $directory . '/components/table.php'; ?>
    <?php if (!$data['leaves']): ?><p class="empty-state">No leave records.</p><?php endif; ?>
</section>
