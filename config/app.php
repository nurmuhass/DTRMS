<?php
declare(strict_types=1);

$envFile = BASE_PATH . '/.env';
$local = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : [];
if ($local === false) {
    throw new RuntimeException('Could not read local environment configuration.');
}

$env = static function (string $key, string $default = '') use ($local): string {
    $value = getenv($key);
    return $value !== false ? $value : ($local[$key] ?? $default);
};

return [
    'environment' => $env('APP_ENV', 'production'),
    'allow_loopback_http' => $env('APP_ALLOW_LOOPBACK_HTTP', '0') === '1',
    'timezone' => $env('APP_TIMEZONE', 'Africa/Lagos'),
    'leave_storage' => $env('LEAVE_STORAGE_DIR'),
    'backup' => [
        'directory' => $env('BACKUP_DIRECTORY'),
        'defaults_file' => $env('BACKUP_DEFAULTS_FILE'),
        'mysqldump' => $env('BACKUP_MYSQLDUMP'),
        'web_roots' => $env('BACKUP_WEB_ROOTS'),
    ],
    'smtp' => [
        'base_url' => $env('APP_BASE_URL'),
        'username' => $env('SMTP_USERNAME'),
        'password' => $env('SMTP_PASSWORD'),
        'relay_host' => $env('SMTP_RELAY_HOST'),
        'ca_file' => $env('SMTP_CA_FILE'),
    ],
    'database' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $env('DB_NAME', 'dtrms'),
        'user' => $env('DB_USER', 'dtrms_app'),
        'password' => $env('DB_PASSWORD'),
    ],
];
