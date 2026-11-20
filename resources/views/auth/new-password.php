<div class="form-field">
    <label for="password">New password</label>
    <input id="password" name="password" type="password" autocomplete="new-password"
           aria-describedby="password-rule" minlength="<?= (int) $data['passwordMinimum'] ?>" required>
    <small id="password-rule"><?= e($data['passwordRule']) ?></small>
</div>
<div class="form-field">
    <label for="confirmation">Confirm new password</label>
    <input id="confirmation" name="confirmation" type="password" autocomplete="new-password" minlength="<?= (int) $data['passwordMinimum'] ?>" required>
</div>
