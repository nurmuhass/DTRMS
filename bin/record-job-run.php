<?php
declare(strict_types=1);

use Dtrms\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$jobs = ['database_backup', 'report_reminder', 'email_delivery', 'maintenance'];
$states = ['running', 'succeeded', 'failed'];
$format = 'Y-m-d\TH:i:s\Z';
$scheduled = DateTimeImmutable::createFromFormat('!' . $format, $argv[2] ?? '', new DateTimeZone('UTC'));
$attempt = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
if ($argc !== 5 || !in_array($argv[1], $jobs, true) || !$scheduled || $scheduled->format($format) !== $argv[2]
    || $scheduled < new DateTimeImmutable('2000-01-01T00:00:00Z') || $scheduled >= new DateTimeImmutable('2038-01-19T00:00:00Z')
    || $attempt === false || !in_array($argv[4], $states, true)) {
    fwrite(STDERR, "Usage: php bin/record-job-run.php <job> <UTC YYYY-MM-DDTHH:MM:SSZ> <attempt> <running|succeeded|failed>\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$db = Database::connect($config['database']);
$query = $db->prepare("INSERT INTO scheduled_job_executions (job_name, scheduled_for, attempt, status, finished_at)
    VALUES (?, ?, ?, ?, IF(? = 'running', NULL, CURRENT_TIMESTAMP))
    ON DUPLICATE KEY UPDATE
        finished_at = IF(status = 'running' AND ? <> 'running', CURRENT_TIMESTAMP, finished_at),
        status = IF(status = 'running', ?, status)");
$query->execute([$argv[1], $scheduled->format('Y-m-d H:i:s'), $attempt, $argv[4], $argv[4], $argv[4], $argv[4]]);
echo "Job run recorded.\n";
