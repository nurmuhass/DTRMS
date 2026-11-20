<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Models\Notifications;
use Dtrms\Models\HrReviews;
use Dtrms\Models\StaffReports;
use Dtrms\Models\SupervisorReports;
use Dtrms\Services\SupervisorApproval;
use Dtrms\Services\HrApproval;

if (PHP_SAPI !== 'cli' || $argc !== 5 || !in_array($argv[4], ['supervisor', 'hr'], true)
    || !str_starts_with(getenv('DB_NAME') ?: '', 'dtrms_test_')) {
    exit(2);
}
$config = require dirname(__DIR__) . '/bootstrap/app.php';
$db = Database::connect($config['database']);
$notifications = new Notifications($db);
$reviewer = $argv[4] === 'hr' ? new HrApproval($db, new HrReviews($db), $notifications)
    : new SupervisorApproval($db, new SupervisorReports($db), new StaffReports($db), $notifications);

echo "ready\n";
flush();
try {
    $reviewer->decide((int) $argv[1], (int) $argv[2], $argv[3], 'approved', 'Concurrent review test');
    echo "approved\n";
} catch (DomainException $error) {
    echo $error->getCode() . "\n";
}
