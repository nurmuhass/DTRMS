<h1><?= e($data['title']) ?></h1>
<form action="<?= e($data['path']) ?>" method="get" class="card hr-filters">
    <?php require $directory . '/supervisor/search-fields.php'; ?>
    <button class="button">Apply filters</button>
    <a href="<?= e($data['path']) ?>">Clear</a>
</form>
<div class="table-scroll" role="region" aria-label="<?= e($data['title']) ?>" tabindex="0">
    <table class="hr-table">
        <thead><tr><th scope="col">Reference</th><th scope="col">Staff member</th><th scope="col">Reporting date</th>
            <th scope="col">Supervisor approval</th><th scope="col">Status</th></tr></thead>
        <tbody>
            <?php foreach ($data['listing']['rows'] as $report): ?>
                <tr><th scope="row"><a href="/hr/reports/view?id=<?= (int) $report['id'] ?>"><?= e(report_reference($report)) ?></a></th>
                    <td><?= e($report['staff_name']) ?><?php if ($report['department_name_snapshot']): ?><br><small><?= e($report['department_name_snapshot']) ?></small><?php endif; ?></td>
                    <td><?= e($report['report_date']) ?></td>
                    <td><?= e($report['supervisor_name']) ?><br><small><?= e(report_timestamp($report['supervisor_approved_at'])) ?></small></td>
                    <td><?php $status = $report['status']; require $directory . '/staff/status.php'; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$data['listing']['rows']): ?><tr><td colspan="5">No reports match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$listPath = $data['path'];
$listFilters = ['name' => $data['name'], 'date' => $data['date']];
require $directory . '/results-pagination.php';
?>
