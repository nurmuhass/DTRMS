<div class="report-heading">
    <div><h1>Staff dashboard</h1><p class="intro">Daily reporting for <?= e($data['principal']['name']) ?></p></div>
    <a class="button" href="/staff/reports/edit">New daily report</a>
</div>
<section class="staff-stats" aria-label="Report totals">
    <?php foreach ([
        'total' => ['Total reports', ''],
        'pending_supervisor' => ['Pending supervisor approval', 'submitted'],
        'pending_hr' => ['Pending HR approval', 'pending_hr'],
        'approved' => ['HR-approved reports', 'hr_approved'],
        'returned' => ['Returned reports', 'returned'],
    ] as $key => [$label, $filter]): ?>
        <a class="staff-stat card" href="/staff/reports<?= $filter === '' ? '' : '?status=' . e($filter) ?>">
            <span><?= e($label) ?></span><strong><?= (int) $data['counts'][$key] ?></strong>
        </a>
    <?php endforeach; ?>
</section>
<div class="staff-dashboard-grid">
    <section class="card">
        <div class="report-heading"><h2>Recent report activity</h2><a href="/staff/reports">View all</a></div>
        <?php if ($data['recent']): ?>
            <ul class="report-activity">
                <?php foreach ($data['recent'] as $report): ?>
                    <li>
                        <div><a href="/staff/reports/view?id=<?= (int) $report['id'] ?>"><?= e(report_reference($report)) ?></a>
                            <span><?= e($report['report_date']) ?> · Updated <?= e(report_timestamp($report['updated_at'])) ?></span></div>
                        <?php $status = $report['status']; require $directory . '/staff/status.php'; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="empty-state">No reports yet. Create a daily report to start your history.</p>
        <?php endif; ?>
    </section>
    <section class="card staff-attendance">
        <h2>Staff-entered attendance</h2>
        <p><?= e($data['month']) ?></p>
        <dl class="report-facts">
            <div><dt>Recorded dates</dt><dd><?= (int) $data['attendance']['days'] ?></dd></div>
            <div><dt>Recorded work duration</dt><dd><?= e(recorded_work_duration((int) $data['attendance']['minutes'])) ?></dd></div>
        </dl>
        <p>Based on the times you entered in your latest submitted report for each date.</p>
    </section>
</div>
