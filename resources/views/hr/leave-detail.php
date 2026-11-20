<?php
$base = \Dtrms\Controllers\StaffLeaveController::PATH;
$leave = $data['leave'];
$id = (int) $leave['id'];
$isPending = $leave['status'] === 'pending';
?>
<div class="hr-heading"><h1>Leave record</h1><a href="<?= e($base) ?>">Back to leave dashboard</a></div>
<section class="card staff-panel">
    <div class="report-heading"><h2><?= e($leave['staff_name']) ?> · <?= e($data['types'][$leave['leave_type']]) ?></h2>
        <span class="report-status"><?= e($data['statuses'][$leave['status']]) ?></span></div>
    <dl class="report-facts">
        <div><dt>Staff ID</dt><dd><?= e($leave['staff_id'] ?? '—') ?></dd></div>
        <div><dt>Department</dt><dd><?= e($leave['department'] ?? '—') ?></dd></div>
        <div><dt>Start date</dt><dd><?= e($leave['start_date']) ?></dd></div>
        <div><dt>End date</dt><dd><?= e($leave['end_date']) ?></dd></div>
        <div><dt>Registered by</dt><dd><?= e($leave['creator_name']) ?></dd></div>
        <?php if ($leave['approved_at']): ?><div><dt>Approved by</dt><dd><?= e($leave['approver_name']) ?> · <?= e(report_timestamp($leave['approved_at'])) ?></dd></div><?php endif; ?>
    </dl>
    <h3>Leave reason</h3><p class="report-text"><?= e($leave['reason']) ?></p>
    <?php if ($leave['overlap_reason']): ?><h3>Overlap authorization</h3><p class="report-text"><?= e($leave['overlap_reason']) ?></p><?php endif; ?>
    <?php if ($leave['decision_note']): ?><h3>Decision reason</h3><p class="report-text"><?= e($leave['decision_note']) ?></p><?php endif; ?>
    <div class="report-actions">
        <?php if ($leave['attachment_path']): ?><a href="<?= e($base) ?>/document?id=<?= $id ?>">Download supporting document</a><?php endif; ?>
        <?php if ($isPending): ?><a href="<?= e($base) ?>/edit?id=<?= $id ?>">Edit pending leave</a><?php endif; ?>
    </div>
</section>
<?php if ($isPending || $leave['status'] === 'approved'): ?>
    <section class="card hr-form">
        <h2><?= $isPending ? 'Leave decision' : 'Cancel approved leave' ?></h2>
        <form action="<?= e($base) ?>/view?id=<?= $id ?>" method="post">
            <?php require $directory . '/auth/csrf.php'; ?>
            <input type="hidden" name="version" value="<?= (int) $leave['lock_version'] ?>">
            <?php if ($isPending): ?>
                <details class="report-version">
                    <summary>Authorize overlapping approved leave</summary>
                    <p><label><input type="checkbox" name="allow_overlap" value="1"> Explicitly authorize an overlap</label></p>
                    <div class="form-field"><label for="overlap-reason">Overlap reason</label>
                        <input id="overlap-reason" name="overlap_reason" maxlength="500"></div>
                </details>
            <?php endif; ?>
            <div class="form-field"><label for="decision-note"><?= $isPending ? 'Reason (required for rejection)' : 'Cancellation reason' ?></label>
                <textarea id="decision-note" name="decision_note" maxlength="500"<?= $isPending ? '' : ' required' ?>></textarea></div>
            <div class="report-actions">
                <?php if ($isPending): ?>
                    <button class="button" name="decision" value="approve" data-confirm="Approve this leave and exempt the affected scheduled days?">Approve leave</button>
                    <button class="button button-secondary" name="decision" value="reject" data-confirm="Reject this leave?">Reject leave</button>
                <?php else: ?>
                    <button class="button button-secondary" name="decision" value="cancel" data-confirm="Cancel this leave and remove its submission exemption?">Cancel leave</button>
                <?php endif; ?>
            </div>
        </form>
    </section>
<?php endif; ?>
