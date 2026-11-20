<dl class="report-facts">
    <?php foreach (['reporter_name' => 'Staff name', 'reporter_staff_code' => 'Staff ID', 'department_name_snapshot' => 'Department', 'report_date' => 'Date of report'] as $key => $label): ?>
        <div><dt><?= e($label) ?></dt><dd><?= e($fields[$key] ?? '—') ?></dd></div>
    <?php endforeach; ?>
    <?php foreach (['arrival_time' => 'Arrival time', 'work_start_time' => 'Time started work', 'work_end_time' => 'Time ended'] as $key => $label): ?>
        <div><dt><?= e($label) ?></dt><dd><?= e(report_clock($fields[$key] ?? null) ?: '—') ?></dd></div>
    <?php endforeach; ?>
    <div><dt>Recorded work duration</dt><dd><?= isset($fields['working_minutes']) ? e(recorded_work_duration((int) $fields['working_minutes'])) : '—' ?><?= !empty($fields['overnight']) ? ' · Overnight' : '' ?></dd></div>
    <div><dt>Declaration</dt><dd><?= isset($fields['declared']) ? ($fields['declared'] ? 'Confirmed' : 'Awaiting confirmation') : '—' ?></dd></div>
</dl>
<?php foreach (['content' => 'Work description', 'achievement' => 'Key achievement of the day', 'challenges' => 'Challenges encountered'] as $key => $label): ?>
    <div class="report-note">
        <h3><?= e($label) ?></h3>
        <p class="report-text"><?= e(($fields[$key] ?? '') !== '' ? $fields[$key] : '—') ?></p>
    </div>
<?php endforeach; ?>
