<h1>System settings</h1>
<?php $values = $data['values']; ?>
<form action="/administration/settings" method="post" data-confirm="Save these organization-wide settings?">
    <?php require $directory . '/auth/csrf.php'; ?>
    <input type="hidden" name="version" value="<?= e($data['version']) ?>">
    <section class="card staff-panel">
        <h2>Application identity</h2>
        <div class="staff-form-grid">
            <?php $fields = ['app_name' => ['Application name', 'text', ['maxlength' => 120, 'required' => true]],
                'organization_name' => ['Organization name', 'text', ['maxlength' => 120]]];
            require $directory . '/admin/settings-fields.php'; ?>
        </div>
    </section>
    <section class="card staff-panel">
        <h2>Working days and reporting</h2>
        <fieldset class="admin-days">
            <legend>Working days</legend>
            <?php foreach ($data['days'] as $number => $day): ?>
                <label><input type="checkbox" name="working_days[]" value="<?= $number ?>"<?= in_array($number, $values['working_days'], true) ? ' checked' : '' ?>> <?= e($day) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="staff-form-grid">
            <?php $fields = ['work_start' => ['Official start time', 'time', ['required' => true]], 'work_end' => ['Official end time', 'time', ['required' => true]],
                'arrival_grace' => ['Arrival grace (minutes)', 'number', ['min' => 0, 'max' => 120, 'required' => true]],
                'report_deadline' => ['Reporting deadline', 'time', ['required' => true]],
                'reminder_time' => ['First reminder time on working days', 'time', ['required' => true]],
                'reminder_interval_minutes' => ['Review reminder interval (minutes)', 'number', ['min' => 15, 'max' => 480, 'required' => true]]];
            require $directory . '/admin/settings-fields.php'; ?>
        </div>
        <label><input type="checkbox" name="reminders_enabled" value="1"<?= $values['reminders_enabled'] ? ' checked' : '' ?>> Enable scheduled reminders</label>
    </section>
    <section class="card staff-panel">
        <h2>Email delivery</h2>
        <p class="intro">Set SMTP credentials through the server environment.</p>
        <div class="staff-form-grid">
            <?php $fields = [
                'smtp_host' => ['SMTP host', 'text', ['maxlength' => 254]],
                'smtp_port' => ['SMTP port', 'number', ['min' => 1, 'max' => 65535, 'required' => true]],
                'email_from' => ['Sender address', 'email', ['maxlength' => 254]],
                'email_from_name' => ['Sender name', 'text', ['maxlength' => 120]],
            ]; require $directory . '/admin/settings-fields.php'; ?>
            <div class="form-field">
                <label for="smtp_encryption">Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption">
                    <option value="starttls"<?= $values['smtp_encryption'] === 'starttls' ? ' selected' : '' ?>>STARTTLS</option>
                    <option value="tls"<?= $values['smtp_encryption'] === 'tls' ? ' selected' : '' ?>>Implicit TLS</option>
                </select>
            </div>
        </div>
        <label><input type="checkbox" name="email_enabled" value="1"<?= $values['email_enabled'] ? ' checked' : '' ?>> Enable SMTP delivery</label>
    </section>
    <section class="card staff-panel">
        <h2>Session and password policy</h2>
        <div class="staff-form-grid">
            <?php $fields = [
                'session_idle_minutes' => ['Session inactivity timeout (minutes)', 'number', ['min' => 5, 'max' => 120, 'required' => true]],
            ]; require $directory . '/admin/settings-fields.php'; ?>
        </div>
        <p class="intro">Sessions also expire after eight hours. New passwords have a 72-byte maximum.</p>
    </section>
    <section class="card staff-panel">
        <h2>Database backups</h2>
        <div class="staff-form-grid">
            <div class="form-field"><label for="backup_frequency">Frequency</label>
                <select id="backup_frequency" name="backup_frequency">
                    <option value="daily"<?= $values['backup_frequency'] === 'daily' ? ' selected' : '' ?>>Daily</option>
                    <option value="weekly"<?= $values['backup_frequency'] === 'weekly' ? ' selected' : '' ?>>Weekly</option>
                </select>
            </div>
            <div class="form-field"><label for="backup_weekday">Weekday for weekly backups</label>
                <select id="backup_weekday" name="backup_weekday">
                    <?php foreach ($data['days'] as $number => $day): ?>
                        <option value="<?= $number ?>"<?= $values['backup_weekday'] === $number ? ' selected' : '' ?>><?= e($day) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php $fields = [
                'backup_time' => ['Backup time', 'time', ['required' => true]],
                'backup_retention_days' => ['Retention (days)', 'number', ['min' => 1, 'max' => 365, 'required' => true]],
            ]; require $directory . '/admin/settings-fields.php'; ?>
        </div>
        <label><input type="checkbox" name="backups_enabled" value="1"<?= $values['backups_enabled'] ? ' checked' : '' ?>> Enable scheduled backups</label>
    </section>
    <button class="button">Save settings</button>
</form>
<section class="card staff-panel admin-logo-panel">
    <h2>Organization logo</h2>
    <?php if ($data['branding']['has_logo']): ?><p><img class="admin-logo-preview" src="/branding/logo" alt="Current organization logo"></p><?php endif; ?>
    <form action="/administration/settings/logo" method="post" enctype="multipart/form-data" class="staff-panel">
        <?php require $directory . '/auth/csrf.php'; ?>
        <input type="hidden" name="action" value="upload">
        <div class="form-field">
            <label for="logo">Logo image</label>
            <input type="file" name="logo" id="logo" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-hint" required>
            <small id="logo-hint">PNG, JPEG, or WebP. Up to 256 KB and 1024 × 1024 pixels.</small>
        </div>
        <button class="button button-secondary">Upload logo</button>
    </form>
    <?php if ($data['branding']['has_logo']): ?>
        <form action="/administration/settings/logo" method="post" data-confirm="Remove the organization logo?">
            <?php require $directory . '/auth/csrf.php'; ?>
            <button name="action" value="remove" class="button button-secondary">Remove logo</button>
        </form>
    <?php endif; ?>
</section>
