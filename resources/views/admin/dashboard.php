<div class="report-heading">
    <div><h1>System administration</h1><p class="intro">Application configuration, security, and operational health.</p></div>
    <div class="report-actions"><a class="button" href="/administration/settings">System settings</a>
        <a class="button button-secondary" href="/administration/export">Administrative Excel</a></div>
</div>
<section class="staff-stats" aria-label="System totals">
    <?php foreach ([
        'users' => 'Registered users', 'active' => 'Active accounts', 'inactive' => 'Inactive accounts',
        'total' => 'Total reports', 'pending_supervisor' => 'Pending supervisor', 'pending_hr' => 'Pending HR',
        'approved' => 'Completed reports', 'failed_logins' => 'Failed logins · 24 h', 'failed_email' => 'Email failures · 24 h',
    ] as $key => $label): ?>
        <div class="staff-stat card"><span><?= e($label) ?></span><strong><?= (int) $data['counts'][$key] ?></strong></div>
    <?php endforeach; ?>
</section>
<section class="card staff-panel">
    <h2>Departmental activity</h2>
    <?php $table = [
        'caption' => 'Submitted reports by department',
        'headers' => ['Department', 'Submitted', 'Pending supervisor', 'Pending HR', 'Completed', 'Returned'],
        'rows' => array_map(static fn (array $d): array => [$d['name'], $d['submitted'], $d['pending_supervisor'],
            $d['pending_hr'], $d['approved'], $d['returned']], $data['departments']),
    ]; require $directory . '/components/table.php'; ?>
</section>
<section class="card staff-panel">
    <div class="report-heading"><h2>Recent system activity</h2><a href="/administration/audit">Audit log</a></div>
    <?php $listing = $data['activity']; $tableLabel = 'Recent system activity'; require $directory . '/admin/table.php'; ?>
</section>
<section class="card staff-panel">
    <div class="report-heading"><h2>Scheduled task execution</h2><a href="/administration/jobs">Scheduled task log</a></div>
    <?php $listing = $data['jobs']; $tableLabel = 'Recent scheduled runs'; require $directory . '/admin/table.php'; ?>
</section>
