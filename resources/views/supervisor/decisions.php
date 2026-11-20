<?php $listPath = $data['approvedOnly'] ? '/supervisor/approved' : '/supervisor/history'; ?>
<h1><?= e($data['title']) ?></h1>
<p>Decisions you recorded for staff currently assigned to you.</p>
<form action="<?= e($listPath) ?>" method="get" class="card hr-filters">
    <?php require $directory . '/supervisor/search-fields.php'; ?>
    <?php if (!$data['approvedOnly']): ?>
        <div class="form-field">
            <label for="decision">Decision</label>
            <select id="decision" name="decision">
                <option value="">All decisions</option>
                <option value="approved"<?= $data['decision'] === 'approved' ? ' selected' : '' ?>>Approved</option>
                <option value="returned"<?= $data['decision'] === 'returned' ? ' selected' : '' ?>>Returned</option>
            </select>
        </div>
    <?php endif; ?>
    <button class="button">Apply filters</button>
    <a href="<?= e($listPath) ?>">Clear</a>
</form>
<?php
$rows = $data['listing']['rows']; $decisionTable = true;
require $directory . '/supervisor/table.php';
$listFilters = ['name' => $data['name'], 'date' => $data['date'], 'decision' => $data['decision']];
require $directory . '/results-pagination.php';
?>
