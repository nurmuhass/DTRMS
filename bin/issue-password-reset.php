<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Services\AccountRecovery;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2 || strlen($argv[1]) > 254 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/issue-password-reset.php <verified-account-email>\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$code = (new AccountRecovery(Database::connect($config['database'])))->issue($argv[1]);
if ($code === null) {
    fwrite(STDERR, "A reset code could not be issued.\n");
    exit(1);
}

// Standard output is the private delivery channel for the authorized operator.
echo $code . "\n";
