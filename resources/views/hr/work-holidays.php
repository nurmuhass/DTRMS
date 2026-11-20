<?php $base = \Dtrms\Controllers\SubmissionMonitoringController::PATH; ?>
<div class="hr-heading"><h1>Non-working holidays</h1><a href="<?= e($base) ?>">Back to submission monitoring</a></div>
<p class="intro">Holidays are unavailable for new weekly date selections. HR can correct a confirmed schedule if a holiday is added later.</p>
<form class="card hr-filters" action="<?= e($base) ?>/holidays" method="get">
    <div class="form-field"><label for="holiday-year">Calendar year</label>
        <input id="holiday-year" type="number" name="year" min="1000" max="9999" value="<?= e($data['year']) ?>" required></div>
    <button class="button button-secondary">Show holidays</button>
</form>
<div class="table-scroll" role="region" tabindex="0" aria-label="Non-working holidays">
    <table class="hr-table">
        <thead><tr><th scope="col">Date</th><th scope="col">Holiday</th><th scope="col">Scope</th><th scope="col">Action</th></tr></thead>
        <tbody>
            <?php foreach ($data['holidays'] as $holiday): ?>
                <tr><td><?= e($holiday['holiday_date']) ?></td><th scope="row"><?= e($holiday['name']) ?></th>
                    <td><?= e($holiday['department'] ?? 'Organization-wide') ?></td><td>
                        <form action="<?= e($base) ?>/holidays" method="post" data-confirm="Remove this holiday from submission calculations?">
                            <?php require $directory . '/auth/csrf.php'; ?>
                            <input type="hidden" name="id" value="<?= (int) $holiday['id'] ?>">
                            <button class="button button-secondary" name="action" value="remove">Remove</button>
                        </form>
                    </td></tr>
            <?php endforeach; ?>
            <?php if (!$data['holidays']): ?><tr><td colspan="4">No holidays configured for this year.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<form class="card hr-form" action="<?= e($base) ?>/holidays" method="post">
    <?php require $directory . '/auth/csrf.php'; ?>
    <h2>Add holiday</h2>
    <div class="hr-fields">
        <div class="form-field"><label for="holiday-date">Date</label><input type="date" name="date" id="holiday-date" required></div>
        <div class="form-field"><label for="holiday-name">Holiday name</label><input name="name" id="holiday-name" maxlength="120" required></div>
        <div class="form-field"><label for="holiday-department">Applies to</label><select name="department" id="holiday-department">
            <option value="">Organization-wide</option>
            <?php foreach ($data['departments'] as $department): ?>
                <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    </div>
    <button class="button" name="action" value="add">Add holiday</button>
</form>
