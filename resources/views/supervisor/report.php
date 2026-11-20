<?php $report = $data['report']; $status = $report['status']; ?>
<div class="report-heading">
    <div><h1>Review report</h1><p class="intro"><?= e(report_reference($report)) ?> · <?= e($report['staff_name']) ?></p></div>
    <div class="report-actions">
        <?php require $directory . '/staff/status.php'; ?>
        <?php $exportId = (int) $report['id']; require $directory . '/analytics/export-links.php'; ?>
    </div>
</div>
<div class="review-layout">
<section class="card staff-panel">
    <h2>Latest submission</h2>
    <p class="intro">Attendance times in this report were entered by the staff member.</p>
    <?php
    $fields = ($data['revisions'][0]['fields'] ?? ['content' => $report['content']]) + ['report_date' => $report['report_date']];
    require $directory . '/staff/report-fields.php';
    ?>
</section>
<?php if ($data['canReview']): ?>
    <section class="card staff-panel review-decision">
        <h2>Supervisor decision</h2>
        <form action="/supervisor/report?id=<?= (int) $report['id'] ?>" method="post">
            <?php require $directory . '/auth/csrf.php'; ?>
            <input type="hidden" name="lock_version" value="<?= (int) $report['lock_version'] ?>">
            <div class="form-field">
                <label for="comments">Supervisor comments</label>
                <textarea id="comments" name="comments" rows="5" maxlength="<?= (int) $data['commentLimit'] ?>" aria-describedby="comment-hint"><?= e($data['comments']) ?></textarea>
                <small id="comment-hint">Enter a correction reason when returning the report.</small>
            </div>
            <div class="report-actions">
                <button class="button" name="decision" value="approved" data-confirm="Approve this submission and send it to HR?">Approve report</button>
                <button class="button button-secondary" name="decision" value="returned" data-confirm="Return this submission to the staff member for correction?">Return for correction</button>
            </div>
        </form>
    </section>
<?php endif; ?>
</div>
<?php require $directory . '/report-review-history.php'; ?>
