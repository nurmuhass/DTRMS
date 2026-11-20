<nav class="hr-nav" aria-label="HR management">
    <a href="/hr-manager"<?= $view === 'hr/dashboard' ? ' aria-current="page"' : '' ?>>HR dashboard</a>
    <a href="/hr-manager/departments"<?= in_array($view, ['hr/departments', 'hr/department'], true) ? ' aria-current="page"' : '' ?>>Departments</a>
    <a href="/hr-manager/staff"<?= in_array($view, ['hr/directory', 'hr/user', 'hr/account', 'hr/assignment', 'hr/credential'], true) ? ' aria-current="page"' : '' ?>>Staff directory</a>
    <a href="/hr-manager/staff/import"<?= $view === 'hr/import' ? ' aria-current="page"' : '' ?>>Import staff</a>
    <a href="/hr-manager/weekly-schedules">Weekly schedules</a>
    <a href="/hr-manager/leaves"<?= in_array($view, ['hr/leaves', 'hr/leave-form', 'hr/leave-detail'], true) ? ' aria-current="page"' : '' ?>>Staff leave</a>
    <a href="/hr-manager/submissions"<?= str_starts_with($view, 'hr/submission') || in_array($view, ['hr/work-holidays', 'records/profile'], true) ? ' aria-current="location"' : '' ?>>Submission monitoring</a>
    <?php if (\Dtrms\Middleware\Access::permits($data['principal'], 'hr_review_reports', 'hr_manager')): ?><a href="/hr">HR reports</a><?php endif; ?>
</nav>
<?php if ($data['message'] !== null): ?>
    <p class="alert hr-notice" role="status"><?= e($data['message']) ?></p>
<?php endif; ?>
<?php if ($data['error'] !== null): ?>
    <p class="alert hr-error" role="alert"><?= e($data['error']) ?></p>
<?php endif; ?>
