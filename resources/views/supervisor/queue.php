<div class="report-heading"><h1>Report review queue</h1><a href="/supervisor/reports">Pending reports</a></div>
<form action="/supervisor/reports" method="get" class="card hr-filters">
    <?php require $directory . '/supervisor/search-fields.php'; ?>
    <div class="form-field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="all">All submitted reports</option>
            <?php foreach ($data['statuses'] as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $data['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="button">Apply filters</button>
</form>
<?php
$rows = $data['listing']['rows']; $decisionTable = false;
require $directory . '/supervisor/table.php';
$listPath = '/supervisor/reports';
$listFilters = ['name' => $data['name'], 'date' => $data['date'], 'status' => $data['status'] ?: 'all'];
require $directory . '/results-pagination.php';
?>
