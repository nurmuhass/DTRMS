<h1>Sign in</h1>
<p>Use your institutional DTRMS account.</p>
<?php require $directory . '/auth/error.php'; ?>
<form action="/login" method="post" class="card auth-form">
    <?php require $directory . '/auth/csrf.php'; ?>
    <div class="form-field">
        <label for="email">Institutional email</label>
        <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required>
    </div>
    <div class="form-field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="button">Sign in</button>
</form>
<p><a href="/forgot-password">Forgot your password?</a></p>
