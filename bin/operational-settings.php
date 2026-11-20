<?php
declare(strict_types=1);

use Dtrms\Core\Database;
use Dtrms\Models\SystemConfiguration;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$settings = (new SystemConfiguration(Database::connect($config['database'])))->configuration();
echo json_encode(['timezone' => $config['timezone']] + array_intersect_key($settings, array_flip([
    'working_days', 'work_start', 'work_end', 'arrival_grace', 'report_deadline',
    'reminders_enabled', 'reminder_time', 'reminder_interval_minutes', 'email_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption',
    'email_from', 'email_from_name', 'backups_enabled', 'backup_frequency', 'backup_weekday',
    'backup_time', 'backup_retention_days',
])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
