<div class="report-heading">
    <div><h1>Supervisor dashboard</h1><p class="intro">Review daily reports for your assigned staff.</p></div>
    <a class="button" href="/supervisor/reports">Open pending queue</a>
</div>
<section class="staff-stats" aria-label="Supervisor totals">
    <?php foreach ([
        'staff' => ['Assigned staff', '/supervisor/staff'],
        'pending' => ['Pending reports', '/supervisor/reports'],
        'approved' => ['Approved reports', '/supervisor/approved'],
        'returned' => ['Returned reports', '/supervisor/history?decision=returned'],
        'actionable' => ['Awaiting action', '/supervisor/reports'],
    ] as $key => [$label, $url]): ?>
        <a class="staff-stat card" href="<?= e($url) ?>"><span><?= e($label) ?></span><strong><?= (int) $data['counts'][$key] ?></strong></a>
    <?php endforeach; ?>
</section>
<section class="card staff-panel">
    <div class="report-heading"><h2>Recent approval activity</h2><a href="/supervisor/history">View history</a></div>
    <?php $rows = $data['recent']; $decisionTable = true; require $directory . '/supervisor/table.php'; ?>
</section>
