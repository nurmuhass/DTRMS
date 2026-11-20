<h1>System overview</h1>
<p class="intro"><?= e($data['principal']['name']) ?> · <?= e(ucwords(str_replace('_', ' ', $data['principal']['role_code']))) ?></p>
<div class="card-grid">
    <?php $workspace = \Dtrms\Helpers\WorkspaceNavigation::PAGES[$data['principal']['role_code']] ?? null; ?>
    <?php if ($workspace && in_array($workspace[1], $data['principal']['permissions'], true)): ?>
        <section class="card">
            <h2><a href="<?= e($workspace[0]) ?>"><?= e($workspace[2]) ?></a></h2>
        </section>
    <?php endif; ?>
    <?php if (\Dtrms\Middleware\Access::permits($data['principal'], 'hr_review_reports', 'hr_manager')): ?>
        <section class="card"><h2><a href="/hr">HR reporting dashboard</a></h2></section>
    <?php endif; ?>
    <?php if (\Dtrms\Services\ReportingAccess::scope($data['principal']) !== null): ?>
        <section class="card"><h2><a href="/reports/analytics">Reporting and analytics</a></h2></section>
    <?php endif; ?>
    <section class="card"><h2><a href="/notifications">Notifications</a></h2></section>
</div>
