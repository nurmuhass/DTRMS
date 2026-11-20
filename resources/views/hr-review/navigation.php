<nav class="hr-nav" aria-label="HR report review">
    <a href="/hr"<?= $view === 'hr-review/dashboard' ? ' aria-current="page"' : '' ?>>Reporting dashboard</a>
    <a href="/hr/reports"<?= ($data['mode'] ?? '') === 'queue' ? ' aria-current="page"' : '' ?>>Approval queue</a>
    <a href="/hr/reports/approved"<?= ($data['mode'] ?? '') === 'approved' ? ' aria-current="page"' : '' ?>>HR-approved reports</a>
    <a href="/hr/reports/returned"<?= ($data['mode'] ?? '') === 'returned' ? ' aria-current="page"' : '' ?>>Returned by HR</a>
    <?php if (\Dtrms\Middleware\Access::permits($data['principal'], 'hr_manage_organization', 'hr_manager')): ?>
        <a href="/hr-manager">Organization management</a>
    <?php endif; ?>
</nav>
<?php if ($data['message'] !== null): ?><p class="alert hr-notice" role="status"><?= e($data['message']) ?></p><?php endif; ?>
<?php if ($data['error'] !== null): ?><p class="alert hr-error" role="alert"><?= e($data['error']) ?></p><?php endif; ?>
