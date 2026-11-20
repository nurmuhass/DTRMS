<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Services\NotificationWorker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$limit = filter_var($argv[1] ?? '25', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
if ($argc > 2 || $limit === false) {
    fwrite(STDERR, "Usage: php bin/notifications-worker.php [batch-size 1-100]\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$worker = new NotificationWorker(Database::connect($config['database']), $config['smtp']);
$result = $worker->run(new DateTimeImmutable(), $limit);
if ($result === null) {
    echo "Another notification worker is running.\n";
    exit;
}
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
exit(in_array('failed', array_column($result, 'status'), true) ? 1 : 0);
