<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Daily task report</title>
<style>
@page { margin: 39pt 42pt 48pt; }
body { font: 10pt/1.55 "DejaVu Sans", sans-serif; color: #091530; margin: 0; }
.hero { background: #0F2044; color: #fff; border-bottom: 4pt solid #C9A84C; padding: 16pt 19pt; }
.hero img { max-width: 95pt; max-height: 42pt; margin-bottom: 6pt; }
.hero p { margin: 0; font-size: 10pt; }
h1 { font-size: 20pt; margin: 3pt 0 0; }
h2 { font-size: 13pt; border-bottom: 1pt solid #C9A84C; margin: 15pt 0 6pt; }
h3 { font-size: 10pt; margin: 8pt 0 2pt; }
table { width: 100%; border-collapse: collapse; }
td { width: 50%; padding: 5pt 7pt; border: 1pt solid #D9E0EA; vertical-align: top; }
td span { color: #475570; font-size: 8pt; }
p { margin: 0 0 5pt; }
.content { white-space: pre-wrap; word-wrap: break-word; }
.note { color: #475570; }
.decision { border-top: 1pt solid #D9E0EA; margin-top: 6pt; padding-top: 4pt; }
</style></head>
<body>
<div class="hero">
    <?php if ($png !== null): ?><img src="data:image/png;base64,<?= base64_encode($png) ?>" alt=""><?php endif; ?>
    <p><?= e($organization) ?></p>
    <h1>Daily task report</h1>
</div>
<h2>Report and attendance</h2>
<table>
    <?php foreach (array_chunk($information, 2) as $pair): ?>
        <tr><?php foreach ($pair as [$label, $value]): ?>
            <td><span><?= e($label) ?></span><br><?= e((string) ($value === '' || $value === null ? '—' : $value)) ?></td>
        <?php endforeach; ?><?php if (count($pair) < 2): ?><td></td><?php endif; ?></tr>
    <?php endforeach; ?>
</table>
<p class="note">Attendance times were entered by the staff member.</p>
<h2>Daily work</h2>
<?php foreach ($work as [$label, $value]): ?>
    <h3><?= e($label) ?></h3><p class="content"><?= e($value === '' || $value === null ? '—' : $value) ?></p>
<?php endforeach; ?>
<h2>Supervisor and HR decisions</h2>
<?php if ($decisions === []): ?><p>No approval decisions recorded.</p><?php endif; ?>
<?php foreach ($decisions as [$revision, $stage, $reviewer, $decision, $timestamp, $comment]): ?>
    <div class="decision">
        <h3><?= e($stage) ?> · <?= e($decision) ?></h3>
        <p><?= e($reviewer) ?> · Submission <?= $revision ?> · <?= e($timestamp) ?></p>
        <p class="content"><?= e($comment ?? '—') ?></p>
    </div>
<?php endforeach; ?>
</body></html>
