<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Services\DatabaseBackup;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$manual = ($argv[1] ?? null) === '--now';
if ($argc > ($manual ? 2 : 1)) {
    fwrite(STDERR, "Usage: php bin/backup.php [--now]\n");
    exit(2);
}

try {
    $config = require dirname(__DIR__) . '/bootstrap/app.php';
    $database = $config['database'];
    $backup = new DatabaseBackup(Database::connect($database), $config['backup'], $database['name'], $database['host'], $database['port']);
    $status = $backup->run(new DateTimeImmutable(), $manual);
    echo "Database backup: $status\n";
    exit($status === 'failed' ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Database backup could not start or record its result. Check database connectivity and permissions.\n");
    exit(1);
}
