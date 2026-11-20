<nav class="staff-nav" aria-label="System administration">
    <a href="/administration"<?= $view === 'admin/dashboard' ? ' aria-current="page"' : '' ?>>Overview</a>
    <a href="/administration/settings"<?= $view === 'admin/settings' ? ' aria-current="page"' : '' ?>>Settings</a>
    <a href="/administration/roles"<?= $view === 'admin/roles' ? ' aria-current="page"' : '' ?>>Roles and permissions</a>
    <a href="/administration/login"<?= $view === 'admin/monitor' && in_array($data['section'], ['login','security','sessions','audit'], true) ? ' aria-current="page"' : '' ?>>Security</a>
    <a href="/administration/errors"<?= $view === 'admin/monitor' && in_array($data['section'], ['errors','email','jobs','backups','database'], true) ? ' aria-current="page"' : '' ?>>Monitoring</a>
</nav>
<?php if ($data['message'] !== null): ?><p class="alert hr-notice" role="status"><?= e($data['message']) ?></p><?php endif; ?>
<?php if ($data['error'] !== null): ?><p class="alert hr-error" role="alert"><?= e($data['error']) ?></p><?php endif; ?>
