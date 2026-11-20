<?php
$filters = $data['filters'];
$input = $filters->values;
$result = $data['result'];
$range = $filters->from . ' to ' . $filters->until;
$stats = $result['stats'];
?>
<div class="report-heading">
    <div><h1>Reporting and analytics</h1><p class="intro">Reporting dates <?= e($range) ?></p></div>
</div>
<form action="/reports/analytics" method="get" class="card analytics-filters">
    <div class="staff-form-grid">
        <?php foreach (['from' => 'From date', 'until' => 'Through date'] as $key => $label): ?>
            <div class="form-field"><label for="<?= e($key) ?>"><?= e($label) ?></label>
                <input type="date" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($input[$key]) ?>"></div>
        <?php endforeach; ?>
        <div class="form-field"><label for="month">Reporting month</label>
            <select id="month" name="month"><option value="">Any month</option>
                <?php for ($month = 1; $month <= 12; $month++): ?>
                    <option value="<?= $month ?>"<?= (int) $input['month'] === $month ? ' selected' : '' ?>><?= e(date('F', mktime(12, 0, 0, $month, 1, 2020))) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-field"><label for="year">Reporting year</label>
            <input type="number" min="1000" max="9999" id="year" name="year" value="<?= e($input['year']) ?>"></div>
        <?php foreach (['department' => 'Department', 'staff' => 'Staff member', 'supervisor' => 'Supervisor'] as $key => $label): ?>
            <?php if ($data['options'][$key] !== []): ?>
                <div class="form-field"><label for="<?= e($key) ?>"><?= e($label) ?></label>
                    <select id="<?= e($key) ?>" name="<?= e($key) ?>"><option value="">All available</option>
                        <?php foreach ($data['options'][$key] as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= $filters->dimensions[$key] === (int) $option['id'] ? ' selected' : '' ?>><?= e($option['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="form-field"><label for="status">Approval status</label>
            <select id="status" name="status"><option value="">All statuses</option>
                <?php foreach (\Dtrms\Models\StaffReports::STATUSES as $code => $label): ?>
                    <?php if ($code === 'supervisor_approved' || ($code === 'draft' && $data['scope'] !== 'staff')) { continue; } ?>
                    <option value="<?= e($code) ?>"<?= $input['status'] === $code || ($input['status'] === 'supervisor_approved' && $code === 'pending_hr') ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="report-actions"><button class="button">Apply filters</button><a href="/reports/analytics">Clear</a></div>
</form>
<section class="staff-stats" aria-label="Report metrics">
    <?php foreach ([
        'Submitted reports' => $stats['submitted'], 'Pending supervisor' => $stats['pending_supervisor'],
        'Pending HR' => $stats['pending_hr'], 'HR approved' => $stats['approved'], 'Returned' => $stats['returned'],
        'Recorded work duration' => recorded_work_duration($stats['minutes']), 'Late from assessed arrivals' => $stats['late'] . ' / ' . $stats['assessed'],
    ] as $label => $value): ?>
        <div class="staff-stat card"><span><?= e($label) ?></span><strong><?= e((string) $value) ?></strong></div>
    <?php endforeach; ?>
</section>
<div class="analytics-charts" data-report-charts="<?= e(json_encode($data['charts'], JSON_THROW_ON_ERROR)) ?>">
    <?php foreach (['daily' => 'Daily submission trend', 'monthly' => 'Monthly submission trend',
        'departments' => 'Departmental submission and missing counts', 'pending' => 'Pending report analysis',
        'arrival' => 'Staff-entered arrival times'] as $chart => $label): ?>
        <section class="card"><h2><?= e($label) ?></h2><div class="analytics-canvas"><canvas data-chart="<?= e($chart) ?>" role="img" aria-label="<?= e($label) ?>"></canvas></div></section>
    <?php endforeach; ?>
</div>
<?php if ($data['exports']): ?>
    <div class="report-actions analytics-downloads" aria-label="Excel exports">
        <?php foreach (['department' => 'Departmental monthly Excel', 'staff' => 'Staff statistics Excel',
            'attendance' => 'Attendance Excel', 'approval' => 'Approval Excel'] as $type => $label): ?>
            <a class="button button-secondary" href="/reports/export?<?= e(http_build_query(['type' => $type] + $input)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<section class="card staff-panel">
    <h2>Monthly departmental reports</h2>
    <p class="intro">All workflow states count toward departmental submissions. Pending reports are already submitted. Missing submissions use the current eligible roster and working dates whose deadlines have passed.</p>
    <?php $table = ['caption' => 'Monthly department summary', 'headers' => \Dtrms\Services\ReportExports::DEPARTMENT_HEADS,
        'rows' => \Dtrms\Services\ReportExports::departmentRows(array_slice($result['departments'], 0, 50))];
    require $directory . '/components/table.php'; ?>
    <?php if (count($result['departments']) > 50): ?><p>Showing the first 50 month and department groups.</p><?php endif; ?>
</section>
<section class="card staff-panel">
    <h2>Staff submission statistics</h2>
    <?php $table = ['caption' => 'Submissions per staff member', 'headers' => \Dtrms\Services\ReportExports::STAFF_HEADS,
        'rows' => array_map(static fn (array $person): array => [$person['staff_name'], $person['staff_code'], $person['department_name'],
            $person['submitted'], $person['pending'], $person['approved']], array_slice($result['people'], 0, 50))];
    require $directory . '/components/table.php'; ?>
    <?php if (count($result['people']) > 50): ?><p>Showing the first 50 staff members.</p><?php endif; ?>
</section>
<section class="card staff-panel">
    <h2>Supervisor and HR approval statistics</h2>
    <?php $table = ['caption' => 'Recorded reviewer decisions', 'headers' => \Dtrms\Services\ReportExports::APPROVAL_HEADS,
        'rows' => array_slice($result['approvals'], 0, 50)]; require $directory . '/components/table.php'; ?>
    <?php if (count($result['approvals']) > 50): ?><p>Showing the first 50 reviewer groups.</p><?php endif; ?>
</section>
<section class="card staff-panel">
    <h2>Attendance summary</h2>
    <p class="intro">Times are staff-entered. Late arrivals exceed the current official start time plus grace. Overnight records are excluded from lateness assessments.</p>
    <?php $table = ['caption' => 'Latest submitted attendance records', 'headers' => \Dtrms\Services\ReportExports::ATTENDANCE_HEADS,
        'rows' => array_slice($result['attendance'], 0, 50)]; require $directory . '/components/table.php'; ?>
    <?php if (count($result['attendance']) > 50): ?><p>Showing the first 50 attendance records.</p><?php endif; ?>
</section>
<?php if ($data['exports']): ?>
    <section class="card staff-panel">
        <h2>Individual reports</h2>
        <div class="table-scroll" role="region" aria-label="Individual report exports" tabindex="0">
            <table class="hr-table">
                <thead><tr><th scope="col">Reference</th><th scope="col">Reporting date</th><th scope="col">Staff member</th>
                    <th scope="col">Status</th><th scope="col">Export</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($result['filtered'], 0, 50) as $report): ?>
                        <tr><th scope="row"><?= e(report_reference($report)) ?></th><td><?= e($report['report_date']) ?></td>
                            <td><?= e($report['staff_name']) ?></td><td><?php $status = $report['status']; require $directory . '/staff/status.php'; ?></td>
                            <td><?php $exportId = (int) $report['id']; require $directory . '/analytics/export-links.php'; ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($result['filtered'] === []): ?><tr><td colspan="5">No reports match these filters.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($result['filtered']) > 50): ?><p>Showing the latest 50 reports. Narrow the filters to find an older report.</p><?php endif; ?>
    </section>
<?php endif; ?>
