<aside id="sidebar" class="sidebar" aria-label="Application sidebar">
    <a class="brand" href="/">DTRMS</a>
    <nav aria-label="Primary navigation">
        <?php $principal = $data['principal'] ?? null; ?>
        <?php if ($principal === null): ?>
            <a class="nav-link" href="/login"<?= $view === 'auth/login' ? ' aria-current="page"' : '' ?>>Sign in</a>
        <?php else: ?>
            <?php if (!$principal['must_change_password']): ?>
                <a class="nav-link" href="/"<?= $view === 'home' ? ' aria-current="page"' : '' ?>>System overview</a>
                <?php $page = \Dtrms\Helpers\WorkspaceNavigation::PAGES[$principal['role_code']] ?? null; ?>
                <?php if ($page !== null && in_array($page[1], $principal['permissions'], true)): ?>
                    <a class="nav-link" href="<?= e($page[0]) ?>"<?= str_starts_with($view, $page[3]) ? ' aria-current="location"' : '' ?>><?= e($page[2]) ?></a>
                <?php endif; ?>
                <?php if (\Dtrms\Middleware\Access::permits($principal, 'hr_review_reports', 'hr_manager')): ?>
                    <a class="nav-link" href="/hr"<?= str_starts_with($view, 'hr-review/') ? ' aria-current="location"' : '' ?>>HR reporting dashboard</a>
                <?php endif; ?>
                <a class="nav-link" href="/notifications"<?= $view === 'notifications' ? ' aria-current="page"' : '' ?>>Notifications</a>
                <?php if (\Dtrms\Services\ReportingAccess::scope($principal) !== null): ?>
                    <a class="nav-link" href="/reports/analytics"<?= $view === 'analytics/dashboard' ? ' aria-current="page"' : '' ?>>Reporting and analytics</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($principal['must_change_password']): ?>
                <a class="nav-link" href="/change-password" aria-current="page">Change password</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
</aside>
