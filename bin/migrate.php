<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$migrator = new Migrator(Database::connect($config['database']), BASE_PATH . '/database/migrations');
$applied = $migrator->migrate();

echo $applied === [] ? "No pending migrations.\n" : 'Applied migrations: ' . implode(', ', $applied) . "\n";
