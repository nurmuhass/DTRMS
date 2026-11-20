<div class="form-field">
    <label for="<?= e($field['name']) ?>"><?= e($field['label']) ?></label>
    <input id="<?= e($field['name']) ?>" name="<?= e($field['name']) ?>"
           type="<?= e($field['type'] ?? 'text') ?>" value="<?= e($field['value'] ?? '') ?>"
           <?= !empty($field['required']) ? 'required' : '' ?>>
</div>
