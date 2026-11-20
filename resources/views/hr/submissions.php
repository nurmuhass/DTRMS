<?php
$base = \Dtrms\Controllers\SubmissionMonitoringController::PATH;
$filters = $data['filters'];
$period = $data['period'];
$selected = ['period' => $period['mode'], 'week' => $period['week'], 'month' => $period['month']];
?>
<div class="hr-heading">
    <h1>Staff Submission Monitoring</h1><a class="button button-secondary" href="<?= e($base) ?>/holidays">Non-working holidays</a>
</div>
<p class="intro"><?= e($period['from']->format('d M Y') . ' – ' . $period['until']->format('d M Y')) ?> · Weekly deadline Sunday at 11:59 p.m. <?= e($period['from']->format('T')) ?></p>
<section class="staff-stats" aria-label="Submission totals">
    <?php foreach (['active_count' => 'Active staff', 'expected_total' => 'Expected submissions', 'actual_total' => 'Actual submissions',
        'outstanding_total' => 'Outstanding submissions', 'complete_total' => 'Fully compliant staff', 'incomplete_total' => 'Incomplete staff',
        'zero_total' => 'Staff with zero submissions', 'leave_total' => 'Staff on approved leave',
        'schedules_submitted'=>'Schedules submitted','schedules_missing'=>'Schedules missing','missed_total'=>'Missed weekly deadline'] as $key => $label): ?>
        <div class="card staff-stat"><span><?= e($label) ?></span><strong><?= (int) $data['listing']['summary'][$key] ?></strong></div>
    <?php endforeach; ?>
</section>
<form action="<?= e($base) ?>" method="get" class="card hr-filters">
    <div class="form-field hr-search"><label for="submission-q">Staff name or ID</label>
        <input type="search" id="submission-q" name="q" maxlength="100" value="<?= e($filters['q']) ?>"></div>
    <div class="form-field"><label for="submission-period">Reporting period</label>
        <select id="submission-period" name="period">
            <option value="week"<?= $period['mode'] === 'week' ? ' selected' : '' ?>>Week</option>
            <option value="month"<?= $period['mode'] === 'month' ? ' selected' : '' ?>>Month</option>
        </select></div>
    <div class="form-field"><label for="submission-week">Week (ISO)</label>
        <input type="week" id="submission-week" name="week" value="<?= e($period['week']) ?>"></div>
    <div class="form-field"><label for="submission-month">Month</label>
        <input type="month" id="submission-month" name="month" value="<?= e($period['month']) ?>"></div>
    <?php foreach (['department' => ['Department', $data['departments']], 'supervisor' => ['Supervisor', $data['supervisors']]] as $key => [$label, $options]): ?>
        <div class="form-field"><label for="submission-<?= e($key) ?>"><?= e($label) ?></label>
            <select id="submission-<?= e($key) ?>" name="<?= e($key) ?>"><option value="">All</option>
                <?php foreach ($options as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= $filters[$key] === (int) $option['id'] ? ' selected' : '' ?>><?= e($option['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
    <?php endforeach; ?>
    <div class="form-field"><label for="submission-status">Submission status</label>
        <select id="submission-status" name="status"><option value="">All statuses</option>
            <?php foreach ($data['states'] as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="form-field"><label for="submission-staff-id">Individual staff</label>
        <select id="submission-staff-id" name="staff"><option value="">All staff</option>
            <?php foreach ($data['staffOptions'] as $person): ?>
                <option value="<?= (int)$person['id'] ?>"<?= $filters['staff']===(int)$person['id']?' selected':'' ?>><?= e($person['name'].' · '.$person['staff_id']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="form-field"><label for="submission-schedule">Schedule status</label>
        <select id="submission-schedule" name="schedule"><option value="">All schedules</option>
            <option value="confirmed"<?= $filters['schedule']==='confirmed'?' selected':'' ?>>Confirmed</option>
            <option value="missing"<?= $filters['schedule']==='missing'?' selected':'' ?>>Schedule Not Submitted</option>
        </select></div>
    <div class="form-field"><label for="submission-leave">Leave status</label>
        <select id="submission-leave" name="leave"><option value="">All staff</option>
            <option value="yes"<?= $filters['leave'] === 'yes' ? ' selected' : '' ?>>Approved leave in period</option>
            <option value="no"<?= $filters['leave'] === 'no' ? ' selected' : '' ?>>No approved leave in period</option>
        </select></div>
    <div class="form-field"><label for="submission-sort">Sort by</label>
        <select id="submission-sort" name="sort">
            <?php foreach ($data['sorts'] as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $filters['sort'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button class="button">Apply filters</button><a href="<?= e($base) ?>">Clear</a>
</form>
<?php if ($data['canExport'] && !$data['error'] && $data['listing']['count'] > 0): ?>
    <form method="post" action="<?= e($base) ?>/export" class="staff-panel" data-download>
        <input type="hidden" name="csrf" value="<?= e($data['csrf']) ?>">
        <?php foreach ($selected + array_diff_key($filters, ['page' => true]) as $key => $value): ?>
            <input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $value) ?>">
        <?php endforeach; ?>
        <button class="button button-secondary">Export filtered staff to Excel</button>
    </form>
<?php endif; ?>
<p class="intro staff-panel">Submitted shows all submissions, including returned reports. Only submissions for due scheduled dates count toward compliance. Approval totals are separate.</p>
<div class="table-scroll" role="region" tabindex="0" aria-label="Staff submission compliance">
    <table class="hr-table">
        <thead><tr><th scope="col">Staff name</th><th scope="col">Staff ID</th><th scope="col">Department</th><th scope="col">Supervisor</th>
            <th scope="col">Selected work dates</th><th scope="col">Approved leave exemptions</th>
            <th scope="col">Expected</th><th scope="col">Submitted</th><th scope="col">Outstanding</th><th scope="col">Schedule status</th>
            <th scope="col">Compliance</th><th scope="col">Status</th><th scope="col">Report approvals</th></tr></thead>
        <tbody>
            <?php foreach ($data['listing']['rows'] as $row): ?>
                <tr><th scope="row"><a href="<?= e($base . '/staff?' . http_build_query($selected + ['staff' => $row['id']])) ?>"><?= e($row['name']) ?></a>
                    <?php if (!$row['is_active']): ?><small>Inactive</small><?php endif; ?></th>
                    <td><?= e($row['staff_id'] ?? '—') ?></td><td><?= e($row['department'] ?? '—') ?></td><td><?= e($row['supervisor'] ?? '—') ?></td>
                    <td><?= e($row['selected_dates'] ?? '—') ?><?php if ($row['passed_dates']): ?><br><small>Passed <?= e($row['passed_dates']) ?></small><?php endif; ?></td>
                    <td><?= e($row['exemption_dates'] ?? '—') ?></td>
                    <td><?= $row['missing_weeks'] && !$row['confirmed_weeks'] && !$row['expected'] ? 'Not set' : (int) $row['expected'] ?></td>
                    <td><?= (int) $row['actual'] ?> <small>(<?= (int) $row['submitted'] ?> matched)</small></td>
                    <td><?= (int) $row['outstanding'] ?> <small>(<?= (int) $row['due_outstanding'] ?> due)</small></td>
                    <td><?= $row['missing_weeks'] ? 'Schedule Not Submitted' : e($row['schedule_states'] ?? 'Legacy calendar') ?></td>
                    <td><?= $row['percentage'] === null ? 'N/A' : e((string) $row['percentage']) . '%' ?></td>
                    <td><span class="report-status" data-status="<?= e(match ($row['compliance_status']) {
                        'complete' => 'hr_approved', 'incomplete', 'none' => 'returned', 'progress' => 'pending_hr', default => 'draft',
                    }) ?>"><?= e($data['states'][$row['compliance_status']]) ?></span></td>
                    <td><?= (int) $row['approved'] ?> HR approved<?php if ($row['returned']): ?> · <?= (int) $row['returned'] ?> returned<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['listing']['rows']): ?><tr><td colspan="13">No staff match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php $listPath = $base; $listFilters = $selected + array_diff_key($filters, ['page' => true]); require $directory . '/results-pagination.php'; ?>
