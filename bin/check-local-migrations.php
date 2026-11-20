<?php
declare(strict_types=1);

use Dtrms\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$database = $config['database'];
printf("Configured host=%s port=%s database=%s\n", $database['host'], $database['port'], $database['name']);
if ($database['host'] !== '127.0.0.1' || (string) $database['port'] !== '33742' || $database['name'] !== 'dtrms_local') {
    fwrite(STDERR, "Target differs from the local repair database. Connection skipped.\n");
    exit(2);
}

try {
    $pdo = Database::connect($database);
    $server = $pdo->query('SELECT DATABASE() AS database_name, @@port AS server_port')->fetch();
    printf("MySQL connection succeeded. Selected database=%s server port=%s\n", $server['database_name'], $server['server_port']);
    if ($server['database_name'] !== 'dtrms_local' || (int) $server['server_port'] !== 33742) {
        fwrite(STDERR, "The connected database or server port did not match the expected target.\n");
        exit(2);
    }

    $history = $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $error) {
    fprintf(STDERR, "Database check failed (SQLSTATE %s, driver code %s).\n", $error->getCode(), $error->errorInfo[1] ?? 'unknown');
    exit(3);
}

printf("Migration history is readable (%d records).\n", count($history));
$files = glob(dirname(__DIR__) . '/database/migrations/*.sql');
if (!$files) {
    fwrite(STDERR, "Migration files could not be listed.\n");
    exit(4);
}
sort($files, SORT_STRING);
$latest = $history === [] ? '' : max(array_keys($history));
$seen = $pending = $conflicts = [];
foreach ($files as $file) {
    $version = basename($file, '.sql');
    $seen[$version] = true;
    if (isset($history[$version])) {
        if (hash_file('sha256', $file) !== $history[$version]) {
            $conflicts[] = "Checksum mismatch: $version";
        }
        continue;
    }

    $pending[] = $version;
    if (strcmp($version, $latest) < 0) {
        $conflicts[] = "Pending file sorts before an applied migration: $version";
    }
}
foreach (array_diff_key($history, $seen) as $version => $checksum) {
    $conflicts[] = "Applied migration file missing: $version";
}

printf("Pending migrations (%d), in execution order:\n", count($pending));
foreach ($pending as $version) {
    echo "  $version\n";
}
printf("Migration conflicts (%d):\n", count($conflicts));
foreach ($conflicts as $conflict) {
    echo "  $conflict\n";
}
exit($conflicts === [] ? 0 : 4);
