<div class="table-scroll" role="region" aria-label="<?= $decisionTable ? 'Supervisor decisions' : 'Assigned staff reports' ?>" tabindex="0">
    <table class="hr-table">
        <thead><tr><th scope="col">Reference</th><th scope="col">Staff member</th><th scope="col">Reporting date</th>
            <th scope="col">Current status</th>
            <?php if ($decisionTable): ?><th scope="col">Decision</th><th scope="col">Decision time</th><?php endif; ?>
        </tr></thead>
        <tbody>
            <?php foreach ($rows as $report): ?>
                <tr>
                    <th scope="row"><a href="/supervisor/report?id=<?= (int) $report['id'] ?>"><?= e(report_reference($report)) ?></a></th>
                    <td><?= e($report['staff_name']) ?></td>
                    <td><?= e($report['report_date']) ?></td>
                    <td><?php $status = $report['status']; require $directory . '/staff/status.php'; ?></td>
                    <?php if ($decisionTable): ?>
                        <td><?= $report['decision'] === 'approved' ? 'Approved' : 'Returned' ?><br><small>Submission <?= (int) $report['revision_number'] ?></small></td>
                        <td><?= e(report_timestamp($report['decided_at'])) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="<?= $decisionTable ? 6 : 4 ?>">No results to display.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
