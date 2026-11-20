<?php
$report = $data['report'];
$status = $report['status'];
?>
<article class="submission-print">
    <div class="report-heading">
        <div><h1>Submitted daily report</h1>
            <p class="intro"><?= e(report_reference($report) . ' · ' . ($report['reporter_name'] ?? $data['staff']['name'])) ?></p></div>
        <div class="report-actions">
            <button type="button" data-print-report class="button button-secondary">Print report</button>
            <?php if (\Dtrms\Services\ReportingAccess::exports($data['principal'])): ?>
                <a href="/reports/export?id=<?= (int) $report['id'] ?>&amp;format=pdf">PDF</a>
            <?php endif; ?>
        </div>
    </div>
    <section class="card staff-panel">
        <?php require $directory . '/staff/status.php'; ?>
        <?php if (in_array($status, \Dtrms\Services\DailyReporting::RETURNED, true)): ?><p>Submitted · Requires correction</p><?php endif; ?>
        <p class="intro">Staff-entered attendance</p>
        <?php $fields = ($data['revisions'][0]['fields'] ?? $report) + ['report_date' => $report['report_date']];
        require $directory . '/staff/report-fields.php'; ?>
    </section>
    <?php require $directory . '/report-review-history.php'; ?>
</article>
