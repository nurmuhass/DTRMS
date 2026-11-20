<?php
$base = \Dtrms\Controllers\StaffLeaveController::PATH;
$leave = $data['leave'];
$values = $data['values'];
$value = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
?>
<div class="hr-heading"><h1><?= e($data['title']) ?></h1><a href="<?= e($base) ?>">Back to leave dashboard</a></div>
<form class="card hr-form" action="<?= e($base . '/edit' . ($leave ? '?id=' . $leave['id'] : '')) ?>" method="post" enctype="multipart/form-data">
    <?php require $directory . '/auth/csrf.php'; ?>
    <?php if ($leave): ?>
        <input type="hidden" name="user_id" value="<?= (int) $leave['user_id'] ?>">
        <input type="hidden" name="version" value="<?= (int) $leave['lock_version'] ?>">
        <p><?= e($leave['staff_name']) ?> · <?= e($leave['staff_id'] ?? '—') ?></p>
    <?php else: ?>
        <div class="form-field"><label for="leave-staff">Staff member</label><select id="leave-staff" name="user_id" required>
            <option value="">Choose staff member</option>
            <?php foreach ($data['staff'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>"<?= (string) $item['id'] === $value('user_id') ? ' selected' : '' ?>><?= e($item['name'] . ' · ' . $item['staff_id']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="form-field"><label for="leave-type">Leave type</label><select id="leave-type" name="leave_type" required>
        <option value="">Choose leave type</option>
        <?php foreach ($data['types'] as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $value('leave_type') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select></div>
    <div class="hr-fields">
        <div class="form-field"><label for="start-date">Start date</label>
            <input id="start-date" type="date" name="start_date" value="<?= e($value('start_date')) ?>" required></div>
        <div class="form-field"><label for="end-date">End date (inclusive)</label>
            <input id="end-date" type="date" name="end_date" value="<?= e($value('end_date')) ?>" required></div>
    </div>
    <div class="form-field"><label for="leave-reason">Reason</label>
        <textarea id="leave-reason" name="reason" maxlength="2000" required><?= e($value('reason')) ?></textarea></div>
    <div class="form-field"><label for="leave-document"><?= !empty($leave['attachment_path']) ? 'Replace supporting document' : 'Supporting document' ?> (optional)</label>
        <input id="leave-document" name="attachment" type="file" accept=".pdf,.png,.jpg,.jpeg"><small>PDF, PNG, or JPEG, up to 5 MB.</small></div>
    <button class="button"><?= $leave ? 'Save changes' : 'Register pending leave' ?></button>
</form>
