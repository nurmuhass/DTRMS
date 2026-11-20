<nav class="staff-nav" aria-label="Staff portal">
    <a href="/staff"<?= $view === 'staff/dashboard' ? ' aria-current="page"' : '' ?>>Staff dashboard</a>
    <a href="/staff/reports"<?= in_array($view, ['staff/history', 'staff/report', 'staff/locked'], true) ? ' aria-current="page"' : '' ?>>My reports</a>
    <a href="/reports/staff-record"<?= $view === 'records/profile' ? ' aria-current="page"' : '' ?>>Weekly and monthly records</a>
    <a href="/staff/schedule">My Weekly Work Schedule</a>
    <a href="/staff/reports/edit"<?= $view === 'staff/form' && !$data['report'] ? ' aria-current="page"' : '' ?>>New daily report</a>
</nav>
<?php if ($data['message'] !== null): ?>
    <p class="alert hr-notice" role="status"><?= e($data['message']) ?></p>
<?php endif; ?>
<?php if ($data['error'] !== null): ?>
    <p class="alert hr-error" role="alert"><?= e($data['error']) ?></p>
<?php endif; ?>
