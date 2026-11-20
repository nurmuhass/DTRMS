<?php
$report = $data['report'];
$fields = $data['fields'];
$profile = $data['profile'];
$id = (int) ($report['id'] ?? 0);
?>
<div class="report-heading">
    <div><h1><?= e($data['title']) ?></h1>
        <?php if ($report): ?><p class="intro"><?= e(report_reference($report)) ?></p><?php endif; ?>
    </div>
    <a href="<?= $id ? '/staff/reports/view?id=' . $id : '/staff/reports' ?>">Cancel</a>
</div>
<?php if (!$data['profileReady']): ?>
    <p class="alert">Ask HR to assign your staff ID and an active department before saving a report.</p>
<?php elseif (!$data['hasSupervisor']): ?>
    <p class="alert">Ask HR to assign an active supervisor before submitting. You can save a draft now.</p>
<?php endif; ?>
<?php if ($data['returnNote']): ?>
    <aside class="card staff-panel" aria-label="Review feedback">
        <h2>Review feedback</h2>
        <p><?= $data['returnNote']['stage'] === 'hr' ? 'HR' : 'Supervisor' ?> · <?= e($data['returnNote']['name']) ?></p>
        <p class="report-text"><?= e($data['returnNote']['comments'] ?? 'No comment provided.') ?></p>
    </aside>
<?php endif; ?>
<form class="staff-report-form" action="/staff/reports/edit<?= $id ? '?id=' . $id : '' ?>" method="post">
    <?php require $directory . '/auth/csrf.php'; ?>
    <?php if ($report): ?><input type="hidden" name="lock_version" value="<?= e((string) ($fields['lock_version'] ?? '')) ?>"><?php endif; ?>
    <section class="card staff-panel">
        <h2>Staff and reporting date</h2>
        <dl class="report-facts">
            <div><dt>Staff name</dt><dd><?= e($profile['name'] ?? $data['principal']['name']) ?></dd></div>
            <div><dt>Staff ID</dt><dd><?= e($profile['staff_code'] ?? 'Unassigned') ?></dd></div>
            <div><dt>Department</dt><dd><?= e($profile['department'] ?? 'Unassigned') ?></dd></div>
        </dl>
        <div class="form-field report-date">
            <label for="report_date">Date of report</label>
            <input id="report_date" name="report_date" type="date" value="<?= e($fields['report_date']) ?>"
                max="<?= e($data['dateLimit']) ?>"<?= $data['returned'] ? ' readonly' : '' ?> required>
        </div>
    </section>
    <section class="card staff-panel">
        <h2>Work times</h2>
        <?php if ($data['workPolicy']): ?>
            <?php $policy = $data['workPolicy']; ?>
            <p class="intro">Official hours <?= e($policy['work_start']) ?>–<?= e($policy['work_end']) ?> · Arrival grace <?= (int) $policy['arrival_grace'] ?> minutes.</p>
            <?php if ($fields['report_date'] >= $policy['weekly_start']): ?>
                <p class="intro">The final weekly deadline is Sunday at 11:59 p.m. organization time. See <a href="/staff/schedule">My Weekly Work Schedule</a> for selected dates.</p>
            <?php else: ?>
                <p class="intro">Reporting deadline <?= e($policy['report_deadline']) ?>.</p>
            <?php endif; ?>
        <?php endif; ?>
        <div class="staff-form-grid">
            <?php foreach (['arrival_time' => 'Arrival time', 'work_start_time' => 'Time started work', 'work_end_time' => 'Time ended'] as $key => $label): ?>
                <div class="form-field">
                    <label for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input id="<?= e($key) ?>" name="<?= e($key) ?>" type="time" value="<?= e(report_clock($fields[$key] ?? null)) ?>">
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($profile['earliest_arrival'])): ?>
            <p class="intro">The department’s overnight window runs from <?= e(report_clock($profile['earliest_arrival'])) ?> to <?= e(report_clock($profile['latest_end'])) ?> the next day. Use the arrival date as the date of report.</p>
        <?php endif; ?>
    </section>
    <section class="card staff-panel">
        <h2>Daily work</h2>
        <?php foreach (['content' => 'Work description', 'achievement' => 'Key achievement of the day', 'challenges' => 'Challenges encountered'] as $key => $label): ?>
            <div class="form-field">
                <label for="<?= e($key) ?>"><?= e($label) ?></label>
                <textarea id="<?= e($key) ?>" name="<?= e($key) ?>" rows="<?= $key === 'content' ? 5 : 3 ?>" maxlength="<?= (int) $data['textLimit'] ?>"<?= $key === 'challenges' ? ' aria-describedby="challenges-hint"' : '' ?>><?= e($fields[$key] ?? '') ?></textarea>
                <?php if ($key === 'challenges'): ?><small id="challenges-hint">Enter “None” if you had no challenges.</small><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <label class="report-declaration"><input type="checkbox" name="declared" value="1"<?= (string) ($fields['declared'] ?? '') === '1' ? ' checked' : '' ?>>
            I declare that the work and times entered in this report are accurate to the best of my knowledge.</label>
    </section>
    <div class="report-actions">
        <button class="button button-secondary" name="action" value="draft"<?= !$data['profileReady'] ? ' disabled' : '' ?>><?= $data['returned'] ? 'Save corrections' : 'Save draft' ?></button>
        <button class="button" name="action" value="submit" data-confirm="Send this completed report to your supervisor for review?"<?= !$data['profileReady'] || !$data['hasSupervisor'] ? ' disabled' : '' ?>><?= $data['returned'] ? 'Resubmit report' : 'Submit report' ?></button>
    </div>
</form>
