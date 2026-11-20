<header class="topbar">
    <?php if (!empty($data['principal'])): ?>
        <button type="button" class="button button-secondary menu-toggle" aria-controls="sidebar" aria-expanded="false">Menu</button>
    <?php endif; ?>
    <?php if (!empty($branding['has_logo'])): ?><img class="organization-logo" src="/branding/logo" alt=""><?php endif; ?>
    <span class="topbar-title"><?= e($branding['app_name'] ?? 'Daily Task Reporting and Management System') ?>
        <?php if (!empty($branding['organization_name'])): ?><small><?= e($branding['organization_name']) ?></small><?php endif; ?>
    </span>
    <div class="topbar-actions">
    <?php if (isset($data['principal'], $data['csrf']) && empty($data['principal']['must_change_password'])
        && \Dtrms\Middleware\Access::permits($data['principal'], 'portal_access')): ?>
        <details class="notification-menu" data-notifications data-csrf="<?= e($data['csrf']) ?>">
            <summary class="toolbar-control" aria-label="Notifications"><span class="notification-icon" aria-hidden="true"></span><span class="toolbar-text">Notifications</span> <span class="notification-count" data-unread hidden></span></summary>
            <div class="notification-popover">
                <div class="report-heading"><strong>Recent notifications</strong><a href="/notifications">View all</a></div>
                <p class="notification-feedback" data-notification-status role="status">Loading notifications…</p>
                <ul class="report-activity" data-notification-items aria-busy="true"></ul>
                <form method="post" action="/notifications/read-all" data-notification-write>
                    <?php require $directory . '/auth/csrf.php'; ?>
                    <button class="button button-secondary" data-mark-all>Mark all as read</button>
                </form>
            </div>
        </details>
    <?php endif; ?>
    <?php if (isset($data['principal'], $data['csrf'])): ?>
        <details class="profile-menu">
            <summary class="toolbar-control" aria-label="Account menu">
                <span class="profile-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($data['principal']['name'], 0, 1))) ?></span>
                <span class="toolbar-text"><?= e($data['principal']['name']) ?></span>
            </summary>
            <div class="profile-popover">
                <strong><?= e($data['principal']['name']) ?></strong>
                <small><?= e(ucwords(str_replace('_', ' ', $data['principal']['role_code']))) ?></small>
                <a href="/change-password">Change password</a>
                <button type="button" class="button button-secondary theme-toggle" data-theme-toggle hidden>Theme</button>
                <form action="/logout" method="post" class="signout-form">
                    <?php require $directory . '/auth/csrf.php'; ?>
                    <button type="submit" class="button button-secondary">Sign out</button>
                </form>
            </div>
        </details>
    <?php else: ?>
        <button type="button" class="button button-secondary theme-toggle" data-theme-toggle hidden>Theme</button>
    <?php endif; ?>
    </div>
</header>
