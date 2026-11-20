<h1><?= e($data['title']) ?></h1>
<nav class="staff-nav" aria-label="Technical monitoring pages">
    <?php foreach ($data['sections'] as $key => $label): ?>
        <a href="/administration/<?= e($key) ?>"<?= $data['section'] === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($data['section'] === 'database'): ?>
    <section class="card staff-panel">
        <h2>Current application connection</h2>
        <?php if ($data['connection']['connected']): ?>
            <p>Connected · Probe time <?= e((string) $data['connection']['milliseconds']) ?> ms</p>
        <?php else: ?>
            <p role="alert">The database probe failed.</p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <?php if (isset($data['suspicious'])): ?>
        <section class="card staff-panel">
            <h2>Suspicious login activity</h2>
            <p><?= (int) $data['suspicious']['accounts'] ?> login identifiers reached eight unsuccessful attempts in the past 15 minutes.</p>
            <?php $table = ['caption' => 'Sources with 30 or more unsuccessful attempts in the past 15 minutes',
                'headers' => ['Source IP', 'Unsuccessful attempts'],
                'rows' => array_map(static fn (array $source): array => [
                    filter_var($source['ip_address'], FILTER_VALIDATE_IP) ? $source['ip_address'] : 'Unknown', $source['failures'],
                ], $data['suspicious']['sources'])];
            require $directory . '/components/table.php'; ?>
        </section>
    <?php endif; ?>
    <?php if ($data['section'] === 'backups'): ?>
        <section class="card staff-panel">
            <h2>Backup policy</h2>
            <p><?= $data['settings']['backups_enabled'] ? 'Enabled' : 'Disabled' ?> · <?= e(ucfirst($data['settings']['backup_frequency'])) ?>
                at <?= e($data['settings']['backup_time']) ?> · Retain for <?= (int) $data['settings']['backup_retention_days'] ?> days</p>
            <dl class="report-facts">
                <div><dt>Latest run</dt><dd><?= e(ucfirst($data['backup']['latest']['status'] ?? 'No runs recorded')) ?></dd></div>
                <div><dt>Last successful backup</dt><dd><?= e(report_timestamp($data['backup']['last_success'])) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>
    <?php $listing = $data['listing']; $tableLabel = $data['title']; require $directory . '/admin/table.php'; ?>
    <?php $listPath = '/administration/' . $data['section']; $listFilters = []; require $directory . '/results-pagination.php'; ?>
<?php endif; ?>
