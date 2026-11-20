<?php $user = $data['user']; $values = $data['values']; $id = (int) ($user['id'] ?? 0); ?>
<div class="hr-heading">
    <h1><?= e($data['title']) ?></h1>
    <?php if ($user): ?>
        <div class="hr-actions">
            <a href="/hr-manager/staff/access?id=<?= $id ?>">Account access</a>
            <?php if ($user['role_code'] === 'staff'): ?><a href="/hr-manager/staff/supervisor?id=<?= $id ?>">Supervisor assignment</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<form action="/hr-manager/staff/edit<?= $id ? '?id=' . $id : '' ?>" method="post" class="card hr-form">
    <?php require $directory . '/auth/csrf.php'; ?>
    <div class="hr-fields">
        <div class="form-field">
            <label for="staff_id">Staff ID</label>
            <input id="staff_id" name="staff_id" value="<?= e($values['staff_id'] ?? '') ?>" maxlength="40" required>
        </div>
        <div class="form-field">
            <label for="name">Full name</label>
            <input id="name" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="150" required>
        </div>
        <div class="form-field">
            <label for="email">Institutional email</label>
            <input type="email" id="email" name="email" value="<?= e($values['email'] ?? '') ?>" maxlength="254" required>
        </div>
        <div class="form-field">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id" required>
                <option value="">Choose a department</option>
                <?php foreach ($data['departmentOptions'] as $department): ?>
                    <?php if ($department['is_active'] || (int) ($user['department_id'] ?? 0) === (int) $department['id']): ?>
                        <option value="<?= (int) $department['id'] ?>"<?= (int) ($values['department_id'] ?? 0) === (int) $department['id'] ? ' selected' : '' ?>><?= e($department['name']) ?><?= $department['is_active'] ? '' : ' (inactive)' ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!$user): ?>
            <div class="form-field">
                <label for="temporary_password">Temporary password</label>
                <input id="temporary_password" name="temporary_password" type="password" autocomplete="new-password"
                       minlength="8" aria-describedby="temporary-password-rule">
                <small id="temporary-password-rule">Password must contain at least 8 characters. Leave blank to generate one.</small>
            </div>
            <div class="form-field">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <?php foreach ($data['roles'] as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= ($values['role'] ?? 'staff') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
    <div class="hr-actions"><button class="button">Save account</button><a href="/hr-manager/staff">Cancel</a></div>
</form>
