<nav class="staff-nav" aria-label="Supervisor portal">
    <a href="/supervisor"<?= $view === 'supervisor/dashboard' ? ' aria-current="page"' : '' ?>>Dashboard</a>
    <a href="/supervisor/reports"<?= in_array($view, ['supervisor/queue', 'supervisor/report'], true) ? ' aria-current="page"' : '' ?>>Review queue</a>
    <a href="/supervisor/staff"<?= $view === 'supervisor/staff' ? ' aria-current="page"' : ($view === 'records/profile' ? ' aria-current="location"' : '') ?>>Assigned staff</a>
    <a href="/supervisor/approved"<?= $view === 'supervisor/decisions' && $data['approvedOnly'] ? ' aria-current="page"' : '' ?>>Approved reports</a>
    <a href="/supervisor/history"<?= $view === 'supervisor/decisions' && !$data['approvedOnly'] ? ' aria-current="page"' : '' ?>>Approval history</a>
</nav>
<?php if ($data['message'] !== null): ?>
    <p class="alert hr-notice" role="status"><?= e($data['message']) ?></p>
<?php endif; ?>
<?php if ($data['error'] !== null): ?>
    <p class="alert hr-error" role="alert"><?= e($data['error']) ?></p>
<?php endif; ?>
