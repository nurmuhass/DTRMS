<div class="table-scroll" role="region" aria-label="<?= e($tableLabel) ?>" tabindex="0">
    <table class="hr-table">
        <thead><tr><?php foreach ($listing['heads'] as $heading): ?><th scope="col"><?= e($heading) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
            <?php foreach ($listing['rows'] as $row): ?>
                <tr><?php foreach ($row as $cell): ?><td><?= e((string) ($cell ?? '—')) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if (!$listing['rows']): ?><tr><td colspan="<?= count($listing['heads']) ?>">No records to display.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
