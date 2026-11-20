<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Services\Installation;
use Dtrms\Services\PasswordPolicy;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 3 || stream_isatty(STDIN)) {
    fwrite(STDERR, "Usage: send the password on stdin to php bin/install-admin.php <name> <email>.\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$db = Database::connect($config['database']);
if ($db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'installation_initialized'")->fetchColumn() === '1') {
    fwrite(STDERR, "Installation is already initialized.\n");
    exit(1);
}

$password = fgets(STDIN);
$password = is_string($password) ? rtrim($password, "\r\n") : '';
if (!PasswordPolicy::acceptable($password)) {
    fwrite(STDERR, PasswordPolicy::MESSAGE . PHP_EOL);
    exit(2);
}

try {
    $installed = (new Installation($db))->initialize($argv[1], $argv[2], $password);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
if (!$installed) {
    fwrite(STDERR, "Installation is already initialized.\n");
    exit(1);
}

echo "Initial administrator created. Change the temporary password at first sign-in.\n";
