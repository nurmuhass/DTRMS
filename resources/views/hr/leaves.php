<?php
$base = \Dtrms\Controllers\StaffLeaveController::PATH;
$mode = $data['mode'];
$filters = $data['filters'];
$month = $data['month'];
?>
<div class="hr-heading"><h1><?= e($data['title']) ?></h1><a class="button" href="<?= e($base) ?>/edit">Register leave</a></div>
<nav class="hr-nav" aria-label="Leave management">
    <?php foreach ($data['modes'] as $key => $label): ?>
        <a href="<?= e($base . '?mode=' . $key) ?>"<?= $key === $mode ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($mode === 'dashboard'): ?>
    <section class="staff-stats" aria-label="Leave totals">
        <?php foreach (['pending' => ['queue','Awaiting decision'], 'current' => ['current','Staff currently on leave'],
            'upcoming' => ['upcoming','Upcoming approved records']] as $key => [$target, $label]): ?>
            <a class="staff-stat card" href="<?= e($base . '?mode=' . $target) ?>"><span><?= e($label) ?></span><strong><?= (int) $data['counts'][$key] ?></strong></a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<form action="<?= e($base) ?>" method="get" class="card hr-filters">
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <div class="form-field hr-search"><label for="q">Staff name or ID</label>
        <input id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>"></div>
    <div class="form-field"><label for="department">Department</label>
        <select id="department" name="department"><option value="">All departments</option>
            <?php foreach ($data['departmentOptions'] as $item): ?>
                <option value="<?= (int) $item['id'] ?>"<?= $filters['department'] === (int) $item['id'] ? ' selected' : '' ?>><?= e($item['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <?php if ($mode === 'calendar'): ?>
        <div class="form-field"><label for="month">Calendar month</label>
            <input id="month" name="month" type="month" value="<?= e($month->format('Y-m')) ?>"></div>
    <?php else: ?>
        <div class="form-field"><label for="date">Leave covering date</label>
            <input id="date" name="date" type="date" value="<?= e($filters['date']) ?>"></div>
    <?php endif; ?>
    <button class="button">Apply filters</button><a href="<?= e($base . '?mode=' . $mode) ?>">Clear</a>
</form>
<?php if ($mode === 'calendar'): ?>
    <?php $calendarFilters = ['q' => $filters['q'], 'department' => $filters['department']]; ?>
    <div class="report-heading">
        <h2><?= e($month->format('F Y')) ?></h2>
        <div class="report-actions">
            <a href="<?= e($base . '?' . http_build_query($calendarFilters + ['mode' => 'calendar', 'month' => $month->modify('-1 month')->format('Y-m')])) ?>">Previous month</a>
            <a href="<?= e($base . '?' . http_build_query($calendarFilters + ['mode' => 'calendar', 'month' => $month->modify('+1 month')->format('Y-m')])) ?>">Next month</a>
        </div>
    </div>
    <div class="table-scroll" role="region" aria-label="Approved leave calendar" tabindex="0">
        <table class="leave-calendar">
            <caption>Staff on approved leave by date</caption>
            <thead><tr><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday): ?><th scope="col"><?= $weekday ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php $first = (int) $month->format('N'); $last = (int) $month->format('t'); ?>
                <?php for ($position = 1; $position <= (int) ceil(($first - 1 + $last) / 7) * 7; $position++): ?>
                    <?php if ($position % 7 === 1): ?><tr><?php endif; ?>
                    <?php $number = $position - $first + 1; ?>
                    <td><?php if ($number >= 1 && $number <= $last): ?>
                        <strong><?= $number ?></strong>
                        <?php if (isset($data['days'][$number])): ?>
                            <a href="<?= e($base . '?' . http_build_query($calendarFilters + ['mode' => 'approved',
                                'date' => $month->format('Y-m-') . sprintf('%02d', $number)])) ?>"><?= (int) $data['days'][$number] ?> on leave</a>
                        <?php endif; ?>
                    <?php endif; ?></td>
                    <?php if ($position % 7 === 0): ?></tr><?php endif; ?>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="table-scroll" role="region" aria-label="<?= e($data['title']) ?>" tabindex="0">
        <table class="hr-table">
            <thead><tr><th scope="col">Staff member</th><th scope="col">Department</th><th scope="col">Leave type</th>
                <th scope="col">Start</th><th scope="col">End</th><th scope="col">Status</th></tr></thead>
            <tbody>
                <?php foreach ($data['listing']['rows'] as $leave): ?>
                    <tr><th scope="row"><a href="<?= e($base) ?>/view?id=<?= (int) $leave['id'] ?>"><?= e($leave['staff_name']) ?></a><small><?= e($leave['staff_id'] ?? '—') ?></small></th>
                        <td><?= e($leave['department'] ?? '—') ?></td><td><?= e($data['types'][$leave['leave_type']]) ?></td>
                        <td><?= e($leave['start_date']) ?></td><td><?= e($leave['end_date']) ?></td>
                        <td><span class="report-status"><?= e($data['statuses'][$leave['status']]) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (!$data['listing']['rows']): ?><tr><td colspan="6">No leave records match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php $listPath = $base; $listFilters = ['mode' => $mode, 'q' => $filters['q'], 'department' => $filters['department'], 'date' => $filters['date']];
        require $directory . '/results-pagination.php'; ?>
<?php endif; ?>
