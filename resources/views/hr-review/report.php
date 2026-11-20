<?php $report = $data['report']; $status = $report['status']; ?>
<div class="report-heading">
    <div><h1>HR report review</h1><p class="intro"><?= e(report_reference($report)) ?> · <?= e($report['reporter_name'] ?? $report['staff_name']) ?></p></div>
    <div class="report-actions">
        <?php require $directory . '/staff/status.php'; ?>
        <?php if (\Dtrms\Services\ReportingAccess::exports($data['principal'])): ?>
            <?php $exportId = (int) $report['id']; require $directory . '/analytics/export-links.php'; ?>
        <?php endif; ?>
    </div>
</div>
<section class="card staff-panel">
    <h2>Supervisor approval</h2>
    <dl class="report-facts">
        <div><dt>Supervisor</dt><dd><?= e($report['supervisor_name']) ?></dd></div>
        <div><dt>Approved at</dt><dd><?= e(report_timestamp($report['supervisor_approved_at'])) ?></dd></div>
        <div><dt>Submission</dt><dd><?= (int) $report['supervised_revision'] ?></dd></div>
    </dl>
    <h3>Supervisor comments</h3>
    <p class="report-text"><?= e($report['supervisor_comments'] ?? 'No comment provided.') ?></p>
</section>
<div class="review-layout">
<section class="card staff-panel">
    <h2>Supervisor-approved submission</h2>
    <p class="intro">Attendance times were entered by the staff member.</p>
    <?php
    $fields = ($data['revisions'][0]['fields'] ?? ['content' => $report['content']]) + ['report_date' => $report['report_date']];
    require $directory . '/staff/report-fields.php';
    ?>
</section>
<?php if ($data['canDecide']): ?>
    <section class="card staff-panel review-decision">
        <h2>HR decision</h2>
        <form action="/hr/reports/view?id=<?= (int) $report['id'] ?>" method="post">
            <?php require $directory . '/auth/csrf.php'; ?>
            <input type="hidden" name="lock_version" value="<?= (int) $report['lock_version'] ?>">
            <div class="form-field">
                <label for="comments">HR comments</label>
                <textarea id="comments" name="comments" rows="5" maxlength="<?= (int) $data['commentLimit'] ?>" aria-describedby="hr-comment-hint"><?= e($data['comments']) ?></textarea>
                <small id="hr-comment-hint">A correction reason is required when returning a report.</small>
            </div>
            <div class="report-actions">
                <button class="button" name="decision" value="approved" data-confirm="Record final HR approval for this submission?">Approve report</button>
                <button class="button button-secondary" name="decision" value="returned" data-confirm="Return this submission to the staff member for correction?">Return for correction</button>
            </div>
        </form>
    </section>
<?php endif; ?>
</div>
<?php require $directory . '/report-review-history.php'; ?>
