<?php
$department = $data['department'];
$id = (int) ($department['id'] ?? 0);
$values = $data['values'];
?>
<div class="hr-heading">
    <h1><?= e($data['title']) ?></h1>
    <?php if ($department): ?><a href="/hr-manager/staff?department=<?= $id ?>">View department staff</a><?php endif; ?>
</div>
<form action="/hr-manager/departments/edit<?= $id ? '?id=' . $id : '' ?>" method="post" class="card hr-form">
    <?php require $directory . '/auth/csrf.php'; ?>
    <div class="form-field">
        <label for="code">Department code</label>
        <input id="code" name="code" value="<?= e($values['code'] ?? '') ?>" maxlength="40" required>
    </div>
    <div class="form-field">
        <label for="name">Department name</label>
        <input id="name" name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="150" required>
    </div>
    <?php if ($department): ?>
        <div class="form-field">
            <label for="hod_id">Head of department</label>
            <select id="hod_id" name="hod_id">
                <option value="">Unassigned</option>
                <?php foreach ($data['hodOptions'] as $candidate): ?>
                    <option value="<?= (int) $candidate['id'] ?>"<?= (int) ($values['hod_id'] ?? 0) === (int) $candidate['id'] ? ' selected' : '' ?>><?= e($candidate['name']) ?><?= $candidate['staff_id'] ? ' · ' . e($candidate['staff_id']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="hr-actions"><button class="button">Save department</button><a href="/hr-manager/departments">Cancel</a></div>
</form>
<?php if ($department): ?>
    <?php $action = $department['is_active'] ? 'Deactivate' : 'Activate'; ?>
    <section class="card hr-form">
        <h2>Department status</h2>
        <p><?= $department['is_active'] ? 'Active' : 'Inactive' ?></p>
        <form action="/hr-manager/departments/status?id=<?= $id ?>" method="post" data-confirm="<?= e($action . ' this department?') ?>">
            <?php require $directory . '/auth/csrf.php'; ?>
            <input type="hidden" name="operation" value="<?= strtolower($action) ?>">
            <button class="button button-secondary"><?= $action ?> department</button>
        </form>
    </section>
<?php endif; ?>
