<nav class="breadcrumb" aria-label="Breadcrumb">
    <ol>
        <?php if ($view !== 'home' && !empty($data['principal']) && !$data['principal']['must_change_password']): ?>
            <li><a href="/">System overview</a></li>
        <?php endif; ?>
        <li aria-current="page"><?= e($data['title']) ?></li>
    </ol>
</nav>
