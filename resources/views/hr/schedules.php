<?php
$path = \Dtrms\Controllers\StaffScheduleController::PATH;
$filters = $data['filters'];
$person = $data['person'];
$planned = $data['planned'];
$values = $data['values'];
$weekdays = $data['weekdays'];
$dayLabels = static fn (array $days): string => implode(', ', array_map(static fn (int $day): string => $weekdays[$day], $days));
$dayValues = array_map('strval', array_filter((array) ($values['days'] ?? $planned['days'] ?? []), 'is_scalar'));
$value = static fn (string $key, string $default = ''): string => is_string($values[$key] ?? null) ? $values[$key] : $default;
?>
<div class="hr-heading">
    <h1><?= $person ? 'Work schedule for ' . e($person['name']) : 'Staff work schedules' ?></h1>
    <?php if ($person): ?><a href="<?= e($path . '?' . http_build_query(array_replace($filters, ['staff' => 0]))) ?>">Back to schedules</a><?php endif; ?>
</div>
<p class="intro">Staff without an individual schedule follow <?= e($dayLabels($data['defaultDays'])) ?> under the default three-day calendar.</p>
<?php if (!$person): ?>
    <form action="<?= e($path) ?>" method="get" class="card hr-filters">
        <div class="form-field hr-search"><label for="q">Staff name or ID</label>
            <input type="search" id="q" name="q" maxlength="100" value="<?= e($filters['q']) ?>"></div>
        <div class="form-field"><label for="department">Department</label><select id="department" name="department">
            <option value="">All departments</option>
            <?php foreach ($data['departments'] as $department): ?>
                <option value="<?= (int) $department['id'] ?>"<?= (int) $department['id'] === $filters['department'] ? ' selected' : '' ?>><?= e($department['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <button class="button">Apply filters</button><a href="<?= e($path) ?>">Clear</a>
    </form>
    <div class="table-scroll" role="region" tabindex="0" aria-label="Staff schedules">
        <table class="hr-table">
            <thead><tr><th scope="col">Select</th><th scope="col">Staff member</th><th scope="col">Department</th>
                <th scope="col">Current individual days</th><th scope="col">Next individual schedule</th></tr></thead>
            <tbody>
            <?php foreach ($data['listing']['rows'] as $staff):
                $id = (int) $staff['id'];
                $current = $next = null;
                foreach ($data['periods'][$id] ?? [] as $period) {
                    if ($period['effective_from'] <= $data['today']) {
                        $current = $period;
                    } else {
                        $next = $period;
                    }
                }
                ?>
                <tr>
                    <td><?php if ($staff['is_active'] && $staff['staff_id'] !== null): ?>
                        <input type="checkbox" name="staff[]" value="<?= $id ?>" form="schedule-form"
                            aria-label="<?= e('Select ' . $staff['name']) ?>"
                            <?= in_array((string) $id, array_map('strval', array_filter((array) ($values['staff'] ?? []), 'is_scalar')), true) ? ' checked' : '' ?>>
                        <?php else: ?>—<?php endif; ?></td>
                    <th scope="row"><a href="<?= e($path . '?' . http_build_query(array_replace($filters, ['staff' => $id]))) ?>"><?= e($staff['name']) ?></a>
                        <small><?= e($staff['staff_id'] ?? 'No staff ID') ?><?= $staff['is_active'] ? '' : ' · Inactive' ?></small></th>
                    <td><?= e($staff['department'] ?? 'No department') ?></td>
                    <td><?= $current ? e($dayLabels($current['days'])) : 'Default calendar' ?></td>
                    <td><?= $next ? e($next['effective_from'] . ' · ' . $dayLabels($next['days'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['listing']['rows']): ?><tr><td colspan="5">No staff match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php $listPath = $path; $listFilters = ['q' => $filters['q'], 'department' => $filters['department']]; require $directory . '/results-pagination.php'; ?>
<?php else: ?>
    <section class="card staff-panel">
        <h2>Schedule history</h2>
        <?php if (!$data['history']): ?><p class="empty-state">No individual schedules have been assigned.</p><?php endif; ?>
        <?php foreach ($data['history'] as $period): ?>
            <div class="report-note">
                <h3><?= e($period['effective_from'] . ' to ' . ($period['effective_to'] ?? 'open-ended')) ?></h3>
                <p><?= e($dayLabels($period['days'])) ?> · <?= $period['status'] === 'cancelled' ? 'Cancelled' : ($period['effective_from'] > $data['today'] ? 'Planned' : ($period['effective_to'] !== null && $period['effective_to'] < $data['today'] ? 'Ended' : 'Current')) ?></p>
                <?php if ($period['exception_reason']): ?><p><?= e($period['exception_reason']) ?></p><?php endif; ?>
                <p>Recorded <?= e(report_timestamp($period['created_at'])) ?></p>
                <?php if ($person['is_active'] && $period['status'] === 'assigned' && $period['effective_from'] > $data['today']): ?>
                    <a href="<?= e($path . '?' . http_build_query($filters + ['replace' => $period['assignment_key']])) ?>">Edit planned schedule</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if (($person && $person['is_active'] && $person['staff_id'] !== null) || (!$person && $data['listing']['rows'])): ?>
    <form id="schedule-form" class="card staff-panel" action="<?= e($path . '?' . http_build_query($filters)) ?>" method="post">
        <?php require $directory . '/auth/csrf.php'; ?>
        <?php if ($person): ?><input type="hidden" name="staff[]" value="<?= (int) $person['id'] ?>"><?php endif; ?>
        <?php if ($planned): ?><input type="hidden" name="replace" value="<?= e($planned['assignment_key']) ?>"><?php endif; ?>
        <h2><?= $planned ? 'Edit planned schedule' : ($person ? 'Assign or change schedule' : 'Assign schedule to selected staff') ?></h2>
        <p class="intro">Changes start tomorrow or later. Days before the effective start stay on their original calendar.</p>
        <div class="hr-fields">
            <div class="form-field"><label for="effective_from">Start date</label><input type="date" name="effective_from" id="effective_from"
                min="<?= e($data['earliest']) ?>" value="<?= e($value('effective_from', $planned['effective_from'] ?? $data['earliest'])) ?>" required></div>
            <div class="form-field"><label for="effective_to">End date (optional, inclusive)</label><input type="date" name="effective_to" id="effective_to"
                min="<?= e($data['earliest']) ?>" value="<?= e($value('effective_to', $planned['effective_to'] ?? '')) ?>"></div>
        </div>
        <fieldset class="admin-days"><legend>Working days (choose three)</legend>
            <?php foreach ($weekdays as $number => $label): ?>
                <label><input type="checkbox" name="days[]" value="<?= $number ?>"<?= in_array((string) $number, $dayValues, true) ? ' checked' : '' ?>><?= e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <p><label><input type="checkbox" name="exception" value="1"<?= $value('exception', empty($planned['exception_reason']) ? '' : '1') === '1' ? ' checked' : '' ?>>
            Authorize a schedule with a different number of days</label></p>
        <div class="form-field"><label for="reason">Exception reason (required for a different number of days)</label>
            <input type="text" name="reason" id="reason" maxlength="500" value="<?= e($value('reason', $planned['exception_reason'] ?? '')) ?>"></div>
        <button class="button" data-confirm="Save this schedule for the selected staff?">Save schedule</button>
    </form>
<?php endif; ?>
