<?php $listing = $data['listing'] ?? $data['pagination']; ?>
<div class="hr-pagination">
    <p><?= (int) $listing['count'] ?> results<?php if ($listing['count']): ?> · Page <?= (int) $listing['page'] ?> of <?= (int) $listing['pages'] ?><?php endif; ?></p>
    <?php if ($listing['pages'] > 1): ?>
        <nav aria-label="Results pages">
            <?php if ($listing['page'] > 1): ?>
                <a class="button button-secondary" rel="prev" href="<?= e($listPath . '?' . http_build_query($listFilters + ['page' => $listing['page'] - 1])) ?>">Previous</a>
            <?php endif; ?>
            <span class="pagination-current" aria-current="page"><?= (int) $listing['page'] ?></span>
            <?php if ($listing['page'] < $listing['pages']): ?>
                <a class="button button-secondary" rel="next" href="<?= e($listPath . '?' . http_build_query($listFilters + ['page' => $listing['page'] + 1])) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
