<?php $user = $data['user']; $id = (int) $user['id']; ?>
<div class="hr-heading"><h1>Supervisor assignment</h1><a href="/hr-manager/staff/edit?id=<?= $id ?>">Edit account</a></div>
<p class="intro"><?= e($user['name']) ?> · Staff ID <?= e($user['staff_id'] ?? 'unassigned') ?></p>
<form action="/hr-manager/staff/supervisor?id=<?= $id ?>" method="post" class="card hr-form">
    <?php require $directory . '/auth/csrf.php'; ?>
    <p>Current supervisor <strong><?= e($user['supervisor'] ?? 'Unassigned') ?></strong></p>
    <div class="form-field">
        <label for="supervisor_id">New supervisor</label>
        <select name="supervisor_id" id="supervisor_id" required>
            <option value="">Choose an active supervisor</option>
            <?php foreach ($data['supervisorOptions'] as $candidate): ?>
                <?php if ((int) $candidate['id'] !== (int) $user['supervisor_id']): ?>
                    <option value="<?= (int) $candidate['id'] ?>"<?= ($data['values']['supervisor_id'] ?? 0) === (int) $candidate['id'] ? ' selected' : '' ?>><?= e($candidate['name']) ?><?= $candidate['staff_id'] ? ' · ' . e($candidate['staff_id']) : '' ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-field">
        <label for="reason">Reason for assignment</label>
        <input name="reason" id="reason" value="<?= e($data['values']['reason'] ?? '') ?>" maxlength="500" required>
    </div>
    <button class="button">Save assignment</button>
</form>
<section class="hr-history">
    <h2>Assignment history</h2>
    <div class="table-scroll" role="region" aria-label="Supervisor assignment history" tabindex="0">
        <table class="hr-table">
            <thead><tr><th scope="col">Changed at (UTC)</th><th scope="col">Previous supervisor</th><th scope="col">New supervisor</th><th scope="col">Changed by</th><th scope="col">Reason</th></tr></thead>
            <tbody>
                <?php foreach ($data['history'] as $record): ?>
                    <tr><td><?= e($record['started_at']) ?></td><td><?= e($record['previous_name'] ?? '—') ?></td>
                        <td><?= e($record['supervisor_name']) ?></td><td><?= e($record['changed_by']) ?></td><td><?= e($record['change_reason'] ?? '—') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$data['history']): ?><tr><td colspan="5">No assignments recorded.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
