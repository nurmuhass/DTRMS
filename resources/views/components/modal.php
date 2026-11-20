<dialog id="<?= e($modal['id']) ?>" aria-labelledby="<?= e($modal['id']) ?>-title">
    <h2 id="<?= e($modal['id']) ?>-title"><?= e($modal['title']) ?></h2>
    <p><?= e($modal['description']) ?></p>
    <form method="dialog"><button class="button">Close</button></form>
</dialog>
