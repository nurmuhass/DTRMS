<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e($data['title']) ?> | <?= e($branding['app_name'] ?? 'DTRMS') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <?php if ($view === 'analytics/dashboard'): ?><script src="/assets/chart.umd.min.js" defer></script><?php endif; ?>
    <script src="/assets/app.js"></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="app-shell<?= empty($data['principal']) ? ' guest-shell' : '' ?>">
        <?php require $directory . '/partials/header.php'; ?>
        <?php if (!empty($data['principal'])) require $directory . '/partials/sidebar.php'; ?>
        <main id="main-content" class="content" tabindex="-1">
            <?php if (!empty($data['principal'])) require $directory . '/partials/breadcrumb.php'; ?>
            <?php if (str_starts_with($view, 'hr/') || ($view === 'records/profile' && $data['scope'] === 'hr')): ?>
                <?php require $directory . '/hr/navigation.php'; ?>
            <?php elseif (str_starts_with($view, 'staff/') || ($view === 'records/profile' && $data['scope'] === 'staff')): ?>
                <?php require $directory . '/staff/navigation.php'; ?>
            <?php elseif (str_starts_with($view, 'supervisor/') || ($view === 'records/profile' && $data['scope'] === 'supervisor')): ?>
                <?php require $directory . '/supervisor/navigation.php'; ?>
            <?php elseif (str_starts_with($view, 'hr-review/')): ?>
                <?php require $directory . '/hr-review/navigation.php'; ?>
            <?php elseif (str_starts_with($view, 'admin/')): ?>
                <?php require $directory . '/admin/navigation.php'; ?>
            <?php endif; ?>
            <?php require $directory . '/' . $view . '.php'; ?>
        </main>
    </div>
    <dialog id="confirm-action" aria-labelledby="confirm-action-title" aria-describedby="confirm-action-message">
        <h2 id="confirm-action-title">Confirm action</h2>
        <p id="confirm-action-message" data-confirm-message></p>
        <form method="dialog" class="hr-actions">
            <button class="button button-secondary" value="cancel" autofocus>Cancel</button>
            <button class="button" value="confirm" data-confirm-submit>Confirm</button>
        </form>
    </dialog>
</body>
</html>
