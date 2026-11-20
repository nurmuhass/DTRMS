<div class="report-heading">
    <h1>Notifications</h1>
    <form method="post" action="/notifications/read-all" data-notification-write>
        <?php require $directory . '/auth/csrf.php'; ?>
        <button class="button button-secondary" data-mark-all<?= $data['unread'] === 0 ? ' disabled' : '' ?>>Mark all as read</button>
    </form>
</div>
<p role="status" data-inbox-feedback></p>
<section class="card staff-panel" aria-label="Notifications inbox">
    <?php if (!$data['listing']['rows']): ?><p class="empty-state">You have no notifications.</p><?php endif; ?>
    <ul class="report-activity">
        <?php foreach ($data['listing']['rows'] as $item): ?>
            <li data-notification-row="<?= (int) $item['id'] ?>"<?= $item['read_at'] === null ? ' class="is-unread"' : '' ?>>
                <div>
                    <strong><?= e($item['message']) ?></strong>
                    <span><?= e(report_timestamp($item['created_at'])) ?>
                        <?php if ($item['report_id']): ?> · <?= e(report_reference(['id' => $item['report_id'], 'reference_number' => $item['reference_number']])) ?><?php endif; ?>
                    </span>
                    <?php if ($item['url'] !== null): ?>
                        <a href="<?= e($item['url']) ?>">Open</a>
                    <?php endif; ?>
                </div>
                <?php if ($item['read_at'] === null): ?>
                    <form action="/notifications/read" method="post" class="report-actions" data-notification-write>
                        <?php require $directory . '/auth/csrf.php'; ?>
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <button class="button button-secondary">Mark as read</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php $listPath = '/notifications'; $listFilters = []; require $directory . '/results-pagination.php'; ?>
