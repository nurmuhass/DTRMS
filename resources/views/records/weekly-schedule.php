<?php
$week = $data['week'];
$weekKey = $week->format('o-\WW');
$from = $week->format('Y-m-d');
$row = $data['row'];
$path = $data['path'];
$actionPath = $path . '?' . http_build_query(['week'=>$weekKey]+($data['hr'] ? ['staff'=>$data['id']] : []));
?>
<div class="hr-heading"><h1><?= e($data['title']) ?></h1><a href="<?= $data['hr'] ? '/hr-manager/submissions' : '/staff' ?>">Back to dashboard</a></div>
<?php if ($data['error']): ?><p class="alert hr-error" role="alert"><?= e($data['error']) ?></p><?php endif; ?>
<?php if ($data['message']): ?><p class="alert hr-notice" role="status"><?= e($data['message']) ?></p><?php endif; ?>
<form class="card hr-filters" method="get" action="<?= e($path) ?>">
    <?php if ($data['hr']): ?>
        <div class="form-field"><label for="weekly-staff">Staff member</label><select name="staff" id="weekly-staff" required>
            <option value="">Choose staff</option>
            <?php foreach($data['staff'] as $person): ?>
                <option value="<?= (int)$person['id'] ?>"<?= (int)$person['id']===$data['id']?' selected':'' ?>><?= e($person['name'].' · '.$person['staff_id']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="form-field"><label for="schedule-week">Reporting week</label><input id="schedule-week" type="week" name="week" value="<?= e($weekKey) ?>" required></div>
    <button class="button button-secondary">View week</button>
</form>
<?php if ($data['id']): ?>
    <section class="card staff-panel">
        <h2><?= e($week->format('d M Y') . ' – ' . $week->modify('+6 days')->format('d M Y')) ?></h2>
        <p><strong><?= e($data['effectiveStatus'] ? ucfirst($data['effectiveStatus']) : 'Schedule Not Submitted') ?></strong></p>
        <?php if ($row && $row['days']): ?><p>Selected dates <?= e(implode(', ',$row['days'])) ?></p><?php endif; ?>
        <p class="intro">Weekly deadline <?= e(\Dtrms\Services\WeeklyCompliance::deadline($week)->format('l, d M Y \a\t H:i T')) ?>.</p>
    </section>
    <?php if ($from >= $data['start'] && $data['editable']): ?>
        <form class="card hr-form" method="post" action="<?= e($actionPath) ?>">
            <?php require $directory . '/auth/csrf.php'; ?>
            <fieldset class="admin-days"><legend>Select three work dates</legend>
                <?php for ($offset=0;$offset<7;$offset++): $day=$week->modify("+$offset days"); $date=$day->format('Y-m-d'); ?>
                    <label><input type="checkbox" name="days[]" value="<?= e($date) ?>"<?= in_array($date,$row['days'] ?? [],true)?' checked':'' ?>>
                        <?= e($day->format('l, d M')) ?></label>
                <?php endfor; ?>
            </fieldset>
            <?php if ($data['hr']): ?>
                <div class="form-field"><label for="weekly-reason">Reason</label><textarea id="weekly-reason" name="reason" maxlength="500" required></textarea></div>
                <div class="report-actions">
                    <button class="button" name="action" value="correct" data-confirm="Record this HR schedule correction?">Correct schedule</button>
                    <?php if ($row && in_array($row['status'],\Dtrms\Models\WeeklySchedules::CONFIRMED,true)): ?>
                        <button class="button button-secondary" name="action" value="approve">Approve schedule</button>
                        <button class="button button-secondary" name="action" value="unlock">Record unlock authorization</button>
                    <?php endif; ?>
                    <?php if ($row && $row['status']!=='cancelled'): ?>
                        <button class="button button-secondary" name="action" value="cancel" data-confirm="Cancel this schedule?">Cancel schedule</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="report-actions">
                    <?php if (!$row || $row['status']==='draft'): ?><button class="button button-secondary" name="action" value="draft">Save draft</button><?php endif; ?>
                    <button class="button" name="action" value="submit" data-weekly-confirm data-confirm="Confirm the selected dates? Your schedule locks when the week begins.">Confirm schedule</button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if ($data['history']): ?>
        <section class="card staff-panel hr-history">
            <h2>Schedule history</h2>
            <ul class="report-activity">
                <?php foreach ($data['history'] as $event):
                    $meta=json_decode($event['metadata'] ?? '{}',true);
                    ?>
                    <li><div><strong><?= e(ucfirst(str_replace('_',' ',substr($event['event'],strlen('weekly_schedule_'))))) ?></strong>
                        <span><?= e(($event['name'] ?? 'Scheduled worker') . ' · ' . report_timestamp($event['created_at'])) ?></span>
                        <p><?= e($meta['reason'] ?? '') ?></p>
                        <?php if (!empty($meta['previous']['days'])): ?><p>Previous dates <?= e(implode(', ',$meta['previous']['days'])) ?></p><?php endif; ?>
                        <?php if (!empty($meta['new']['days'])): ?><p>Recorded dates <?= e(implode(', ',$meta['new']['days'])) ?></p><?php endif; ?>
                    </div></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php endif; ?>
