<?php foreach ($fields as $key => [$label, $type, $attributes]): ?>
    <div class="form-field">
        <label for="<?= e($key) ?>"><?= e($label) ?></label>
        <input id="<?= e($key) ?>" name="<?= e($key) ?>" type="<?= e($type) ?>" value="<?= e((string) $values[$key]) ?>"
            <?php foreach ($attributes as $name => $value): ?>
                <?= e($name) ?><?= $value === true ? '' : '="' . e((string) $value) . '"' ?>
            <?php endforeach; ?>>
    </div>
<?php endforeach; ?>
