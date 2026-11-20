<section class="card staff-panel">
    <h2>Approval history and comments</h2>
    <?php if (!$data['approvals']): ?><p class="empty-state">No approval decisions recorded.</p><?php endif; ?>
    <?php foreach ($data['approvals'] as $decision): ?>
        <article class="report-note">
            <h3><?= $decision['stage'] === 'hr' ? 'HR' : 'Supervisor' ?> · <?= $decision['decision'] === 'approved' ? 'Approved' : 'Returned' ?></h3>
            <p class="intro"><?= e($decision['name']) ?> · Submission <?= (int) $decision['revision_number'] ?> · <?= e(report_timestamp($decision['created_at'])) ?></p>
            <p class="report-text"><?= e($decision['comments'] ?? 'No comment provided.') ?></p>
        </article>
    <?php endforeach; ?>
</section>
<section class="card staff-panel">
    <h2>Submission history</h2>
    <?php if (!$data['revisions']): ?><p class="empty-state">No submission snapshots recorded.</p><?php endif; ?>
    <?php foreach ($data['revisions'] as $revision): ?>
        <details class="report-version">
            <summary>Submission <?= (int) $revision['revision_number'] ?> · <?= e(report_timestamp($revision['submitted_at'])) ?></summary>
            <?php $fields = $revision['fields']; require $directory . '/staff/report-fields.php'; ?>
        </details>
    <?php endforeach; ?>
</section>
