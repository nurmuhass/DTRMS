<h1>Assigned staff</h1>
<form action="/supervisor/staff" method="get" class="card hr-filters">
    <div class="form-field hr-search">
        <label for="name">Staff name</label>
        <input id="name" name="name" type="search" maxlength="100" value="<?= e($data['name']) ?>">
    </div>
    <button class="button">Search</button>
    <a href="/supervisor/staff">Clear</a>
</form>
<div class="table-scroll" role="region" aria-label="Assigned staff" tabindex="0">
    <table class="hr-table">
        <thead><tr><th scope="col">Staff member</th><th scope="col">Staff ID</th><th scope="col">Department</th>
            <th scope="col">Account status</th><th scope="col">Assigned since</th></tr></thead>
        <tbody>
            <?php foreach ($data['listing']['rows'] as $person): ?>
                <tr><th scope="row"><a href="/reports/staff-record?staff=<?= (int) $person['id'] ?>"><?= e($person['name']) ?></a></th><td><?= e($person['staff_id'] ?? '—') ?></td>
                    <td><?= e($person['department'] ?? '—') ?></td><td><?= $person['is_active'] ? 'Active' : 'Inactive' ?></td>
                    <td><?= e(report_timestamp($person['started_at'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$data['listing']['rows']): ?><tr><td colspan="5">No assigned staff match your search.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php $listPath = '/supervisor/staff'; $listFilters = ['name' => $data['name']]; ?>
<?php require $directory . '/results-pagination.php'; ?>
