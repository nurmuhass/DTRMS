<h1>Reset password</h1>
<?php require $directory . '/auth/error.php'; ?>
<form action="/reset-password" method="post" class="card auth-form">
    <?php require $directory . '/auth/csrf.php'; ?>
    <div class="form-field">
        <label for="code">One-time reset code</label>
        <input id="code" name="code" type="text" maxlength="64" autocomplete="off" spellcheck="false" required>
    </div>
    <?php require $directory . '/auth/new-password.php'; ?>
    <button class="button">Reset password</button>
</form>
<p><a href="/login">Return to sign in</a></p>
