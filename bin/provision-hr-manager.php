<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Services\Installation;
use Dtrms\Services\PasswordPolicy;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php bin/provision-hr-manager.php <staff-id> <name> <institutional-email>\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$db = Database::connect($config['database']);
$password = PasswordPolicy::temporary();
try {
    (new Installation($db))->provisionHrManager($argv[1], $argv[2], $argv[3], $password);
} catch (DomainException | InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}

echo "HR Manager created. Deliver this temporary password through the approved private channel.\n";
echo $password . "\n";
