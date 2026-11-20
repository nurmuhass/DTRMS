<?php $report = $data['report']; $status = $report['status']; ?>
<div class="report-heading">
    <div><h1>Report details</h1><p class="intro"><?= e(report_reference($report)) ?></p></div>
    <div class="report-actions">
        <?php require $directory . '/staff/status.php'; ?>
        <?php $exportId = (int) $report['id']; require $directory . '/analytics/export-links.php'; ?>
        <?php if ($data['editable']): ?>
            <a class="button" href="/staff/reports/edit?id=<?= (int) $report['id'] ?>"><?= $status === 'draft' ? 'Edit draft' : 'Correct report' ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card staff-panel">
    <h2>Current report</h2>
    <p class="intro">First submitted <?= e(report_timestamp($report['first_submitted_at'])) ?> · Last submitted <?= e(report_timestamp($report['submitted_at'])) ?></p>
    <?php $fields = $report; require $directory . '/staff/report-fields.php'; ?>
</section>
<?php require $directory . '/report-review-history.php'; ?>
