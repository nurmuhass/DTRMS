<div class="hr-heading">
    <h1>Staff directory</h1>
    <div class="hr-actions">
        <a class="button" href="/hr-manager/staff/edit">Create account</a>
        <a class="button button-secondary" href="/hr-manager/staff/import">Import staff</a>
    </div>
</div>
<form action="/hr-manager/staff" method="get" class="card hr-filters">
    <div class="form-field hr-search">
        <label for="q">Name, email, or staff ID</label>
        <input type="search" name="q" id="q" maxlength="100" value="<?= e($data['query']) ?>">
    </div>
    <div class="form-field">
        <label for="role">Role</label>
        <select name="role" id="role">
            <option value="">All roles</option>
            <?php foreach ($data['roles'] as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= $data['role'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-field">
        <label for="department">Department</label>
        <select name="department" id="department">
            <option value="">All departments</option>
            <?php foreach ($data['departmentOptions'] as $department): ?>
                <option value="<?= (int) $department['id'] ?>"<?= $data['department'] === (int) $department['id'] ? ' selected' : '' ?>><?= e($department['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-field">
        <label for="status">Status</label>
        <select name="status" id="status">
            <option value="">All statuses</option>
            <option value="active"<?= $data['status'] === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $data['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <button class="button">Apply filters</button>
    <a href="/hr-manager/staff">Clear</a>
</form>
<div class="table-scroll" role="region" aria-label="Staff directory" tabindex="0">
    <table class="hr-table">
        <thead><tr><th scope="col">Staff ID</th><th scope="col">Name and email</th><th scope="col">Role</th><th scope="col">Department</th><th scope="col">Supervisor</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
        <tbody>
            <?php foreach ($data['pagination']['rows'] as $person): ?>
                <?php $id = (int) $person['id']; ?>
                <tr>
                    <td><?= e($person['staff_id'] ?? 'Unassigned') ?></td>
                    <th scope="row"><?= e($person['name']) ?><small><?= e($person['email']) ?></small></th>
                    <td><?= e($person['role_name']) ?></td>
                    <td><?= e($person['department'] ?? 'Unassigned') ?></td>
                    <td><?= e($person['supervisor'] ?? ($person['role_code'] === 'staff' ? 'Unassigned' : '—')) ?></td>
                    <td><?= $person['is_active'] ? 'Active' : 'Inactive' ?></td>
                    <td><a href="/hr-manager/staff/edit?id=<?= $id ?>">Edit</a>
                        <a href="/hr-manager/staff/access?id=<?= $id ?>">Account access</a>
                        <?php if ($person['role_code'] === 'staff'): ?><a href="/hr-manager/staff/supervisor?id=<?= $id ?>">Supervisor</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['pagination']['rows']): ?><tr><td colspan="7">No accounts match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$listPath = '/hr-manager/staff';
$listFilters = ['q' => $data['query'], 'role' => $data['role'], 'department' => $data['department'] ?: '', 'status' => $data['status']];
require $directory . '/hr/pagination.php';
?>
