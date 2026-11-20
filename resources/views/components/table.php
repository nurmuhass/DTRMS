<div class="table-scroll" role="region" aria-label="<?= e($table['caption']) ?>" tabindex="0">
    <table>
        <caption><?= e($table['caption']) ?></caption>
        <thead><tr>
            <?php foreach ($table['headers'] as $heading): ?>
                <th scope="col"><?= e($heading) ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
            <?php foreach ($table['rows'] as $row): ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                        <td><?= e((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
