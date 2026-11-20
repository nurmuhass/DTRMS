<?php
declare(strict_types=1);

use Dtrms\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$db = Database::connect($config['database']);
$files = glob(BASE_PATH . '/database/seeds/*.sql');
if ($files === false || $files === []) {
    throw new RuntimeException('Seed files are missing.');
}
sort($files, SORT_STRING);

Database::transaction($db, static function () use ($db, $files): void {
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Seed file is empty or unreadable.');
        }
        $db->exec($sql);
    }
});

echo "Initial roles, permissions and installation state seeded.\n";
