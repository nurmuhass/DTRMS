<h1>Roles and permissions</h1>
<p>Manage display names and approved permission grants for the five system roles.</p>
<?php $selected = $data['selected']; ?>
<nav class="staff-nav" aria-label="Roles">
    <?php foreach ($data['roles'] as $role): ?>
        <a href="/administration/roles?role=<?= (int) $role['id'] ?>"<?= $selected && $selected['id'] === $role['id'] ? ' aria-current="page"' : '' ?>><?= e($role['name']) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($selected): ?>
    <form class="card staff-panel" action="/administration/roles?role=<?= (int) $selected['id'] ?>" method="post" data-confirm="Apply these permission changes to every account with this role?">
        <?php require $directory . '/auth/csrf.php'; ?>
        <input type="hidden" name="version" value="<?= e($data['version']) ?>">
        <div class="form-field report-date">
            <label for="name">Role display name</label>
            <input id="name" name="name" value="<?= e($selected['name']) ?>" maxlength="100" required>
        </div>
        <fieldset class="admin-grants">
            <legend>Permission grants</legend>
            <?php foreach ($selected['permissions'] as $permission): ?>
                <?php $manageable = in_array($permission['code'], $selected['managed'], true); ?>
                <?php if ($manageable || $permission['granted']): ?>
                    <label>
                        <input type="checkbox" name="permissions[]" value="<?= e($permission['code']) ?>"<?= $permission['granted'] ? ' checked' : '' ?><?= !$manageable ? ' disabled' : '' ?>>
                        <span><?= e($permission['description']) ?><small><?= e($permission['code']) ?><?= !$manageable ? ' · Fixed grant' : '' ?></small></span>
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
        </fieldset>
        <div class="report-actions"><button class="button">Save role</button></div>
    </form>
<?php endif; ?>
