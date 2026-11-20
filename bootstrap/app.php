<?php
declare(strict_types=1);

use Dtrms\Core\ErrorHandler;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dtrms\\';
    if (str_starts_with($class, $prefix)) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

ErrorHandler::register(BASE_PATH . '/storage/logs/application.log');

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

require BASE_PATH . '/app/Helpers/view.php';
$config = require BASE_PATH . '/config/app.php';
date_default_timezone_set($config['timezone']);

return $config;
