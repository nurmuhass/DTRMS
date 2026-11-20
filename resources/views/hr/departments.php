<div class="hr-heading">
    <h1>Departments</h1>
    <a class="button" href="/hr-manager/departments/edit">Create department</a>
</div>
<form action="/hr-manager/departments" method="get" class="card hr-filters">
    <div class="form-field">
        <label for="q">Department name or code</label>
        <input type="search" name="q" id="q" maxlength="100" value="<?= e($data['search']) ?>">
    </div>
    <button class="button">Search</button>
    <?php if ($data['search'] !== ''): ?><a href="/hr-manager/departments">Clear search</a><?php endif; ?>
</form>
<div class="table-scroll" role="region" aria-label="Departments" tabindex="0">
    <table class="hr-table">
        <thead><tr><th scope="col">Code</th><th scope="col">Department</th><th scope="col">HOD</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
        <tbody>
            <?php foreach ($data['pagination']['rows'] as $department): ?>
                <tr>
                    <td><?= e($department['code']) ?></td>
                    <th scope="row"><?= e($department['name']) ?></th>
                    <td><?= e($department['hod'] ?? 'Unassigned') ?></td>
                    <td><?= $department['is_active'] ? 'Active' : 'Inactive' ?></td>
                    <td><a href="/hr-manager/departments/edit?id=<?= (int) $department['id'] ?>">Edit</a>
                        <a href="/hr-manager/staff?department=<?= (int) $department['id'] ?>">View staff</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['pagination']['rows']): ?><tr><td colspan="5">No departments match your search.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php $listPath = '/hr-manager/departments'; $listFilters = ['q' => $data['search']]; ?>
<?php require $directory . '/hr/pagination.php'; ?>
