<h1>Change password</h1>
<?php if ($data['principal']['must_change_password']): ?>
    <p>Choose a new password before continuing to your workspace.</p>
<?php endif; ?>
<?php require $directory . '/auth/error.php'; ?>
<form action="/change-password" method="post" class="card auth-form">
    <?php require $directory . '/auth/csrf.php'; ?>
    <div class="form-field">
        <label for="current-password">Current password</label>
        <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
    </div>
    <?php require $directory . '/auth/new-password.php'; ?>
    <button class="button">Change password</button>
</form>
