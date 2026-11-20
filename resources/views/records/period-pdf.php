<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($title) ?></title>
<style>
body { font: 10pt DejaVu Sans, sans-serif; color: #091530; }
h1 { font-size: 17pt; margin: 6pt 0; }
h2 { font-size: 13pt; border-bottom: 1pt solid #C9A84C; margin-top: 14pt; padding-bottom: 4pt; }
h3 { font-size: 11pt; margin-bottom: 3pt; }
p { margin: 4pt 0 7pt; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4pt; border-bottom: .5pt solid #d9e1ec; text-align: left; vertical-align: top; }
th { width: 36%; background-color: #F5F7FA; }
.multiline { white-space: pre-wrap; overflow-wrap: break-word; }
small { color: #45546a; }
</style>
</head>
<body>
<strong><?= e($organization) ?></strong>
<h1><?= e($title) ?></h1>
<table>
<?php foreach ($summary as [$label, $value]): ?>
    <tr><th scope="row"><?= e($label) ?></th><td><?= e($value === null ? '—' : (string) $value) ?></td></tr>
<?php endforeach; ?>
</table>
<h2>Scheduled dates exempted by approved leave</h2>
<p><?= $exemptions === [] ? 'None in the selected period.' : e(implode(', ', $exemptions)) ?></p>
<h2>Daily submissions and approval history</h2>
<p><small>Attendance is staff-entered. Returned reports require correction.</small></p>
<?php if ($reports === []): ?><p>No submitted reports in this period.</p><?php endif; ?>
<?php foreach ($reports as [$reference, $date, $department, $status, $first, $last, $arrival, $start, $end, $minutes, $work, $achievements, $challenges]): ?>
    <h3><?= e($date . ' · ' . $reference) ?></h3>
    <p><?= e($status . ' · ' . ($department ?? '—')) ?></p>
    <p>First submitted <?= e($first) ?> · Last submitted <?= e($last) ?></p>
    <p>Arrival <?= e($arrival ?: '—') ?> · Started <?= e($start ?: '—') ?> · Ended <?= e($end ?: '—') ?>
        · <?= e($minutes === null ? 'No recorded duration' : recorded_work_duration($minutes)) ?></p>
    <?php foreach (['Work description' => $work, 'Achievements' => $achievements, 'Challenges' => $challenges] as $label => $text): ?>
        <?php if ($text !== null && $text !== ''): ?><p class="multiline"><strong><?= e($label) ?></strong><br><?= e($text) ?></p><?php endif; ?>
    <?php endforeach; ?>
    <?php foreach ($decisionsByReference[$reference] ?? [] as [, , $revision, $stage, $decision, $reviewer, $at, $comments]): ?>
        <p class="multiline"><strong><?= e($stage . ' · ' . $decision) ?></strong> · Revision <?= $revision ?> · <?= e($reviewer . ' · ' . $at) ?>
            <?php if ($comments): ?><br><?= e($comments) ?><?php endif; ?></p>
    <?php endforeach; ?>
<?php endforeach; ?>
</body>
</html>
