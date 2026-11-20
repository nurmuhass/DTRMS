<div class="report-heading">
    <h1>My reports</h1><a class="button" href="/staff/reports/edit">New daily report</a>
</div>
<form action="/staff/reports" method="get" class="card hr-filters">
    <div class="form-field">
        <label for="date">Reporting date</label>
        <input type="date" id="date" name="date" value="<?= e($data['date']) ?>">
    </div>
    <div class="form-field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach ($data['statuses'] as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= $data['status'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="button">Apply filters</button>
    <a href="/staff/reports">Clear</a>
</form>
<div class="table-scroll" role="region" aria-label="My report history" tabindex="0">
    <table class="hr-table">
        <thead><tr><th scope="col">Reference</th><th scope="col">Reporting date</th><th scope="col">Status</th><th scope="col">Last submitted</th></tr></thead>
        <tbody>
            <?php foreach ($data['listing']['rows'] as $report): ?>
                <tr>
                    <th scope="row"><a href="/staff/reports/view?id=<?= (int) $report['id'] ?>"><?= e(report_reference($report)) ?></a></th>
                    <td><?= e($report['report_date']) ?></td>
                    <td><?php $status = $report['status']; require $directory . '/staff/status.php'; ?></td>
                    <td><?= e(report_timestamp($report['submitted_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['listing']['rows']): ?><tr><td colspan="4">No reports match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$listPath = '/staff/reports';
$listFilters = ['date' => $data['date'], 'status' => $data['status']];
require $directory . '/results-pagination.php';
?>
