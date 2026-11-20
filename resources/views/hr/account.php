<?php $user = $data['user']; $id = (int) $user['id']; ?>
<div class="hr-heading"><h1>Account access</h1><a href="/hr-manager/staff/edit?id=<?= $id ?>">Edit account</a></div>
<p class="intro"><?= e($user['name']) ?> · <?= e($user['email']) ?></p>
<div class="card-grid">
    <section class="card">
        <h2>Account status</h2>
        <p><?= $user['is_active'] ? 'Active' : 'Inactive' ?></p>
        <?php $statusAction = $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
        <form action="/hr-manager/staff/access?id=<?= $id ?>" method="post" data-confirm="<?= e($statusAction . ' this account?') ?>">
            <?php require $directory . '/auth/csrf.php'; ?>
            <input type="hidden" name="operation" value="<?= strtolower($statusAction) ?>">
            <button class="button button-secondary"><?= $statusAction ?> account</button>
        </form>
    </section>
    <?php if ($user['is_active']): ?>
        <section class="card">
            <h2>Reset password</h2>
            <p>Generate a temporary password and end existing sessions.</p>
            <form action="/hr-manager/staff/access?id=<?= $id ?>" method="post" data-confirm="Reset this password and end existing sessions?">
                <?php require $directory . '/auth/csrf.php'; ?>
                <input type="hidden" name="operation" value="reset">
                <button class="button button-secondary">Reset password</button>
            </form>
        </section>
        <section class="card">
            <h2>Require password change</h2>
            <p><?= $user['must_change_password'] ? 'A password change is already required.' : 'Require a password change at the next sign-in and end existing sessions.' ?></p>
            <form action="/hr-manager/staff/access?id=<?= $id ?>" method="post" data-confirm="Require a password change and end existing sessions?">
                <?php require $directory . '/auth/csrf.php'; ?>
                <input type="hidden" name="operation" value="force">
                <button class="button button-secondary">Require password change</button>
            </form>
        </section>
    <?php endif; ?>
</div>
