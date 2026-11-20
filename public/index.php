<?php
declare(strict_types=1);

use Dtrms\Core\Router;
use Dtrms\Services\BrowserSession;

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = explode('?', $uri, 2)[0];
if (PHP_SAPI === 'cli-server' && in_array($path, ['/assets/app.css', '/assets/app.js', '/assets/chart.umd.min.js'], true)) {
    return false;
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$loopbackHttp = $config['environment'] === 'development' && $config['allow_loopback_http']
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
header('Cache-Control: no-store');
$https = in_array(strtolower($_SERVER['HTTPS'] ?? ''), ['on', '1'], true);
if (!$loopbackHttp && !$https) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo 'DTRMS requires HTTPS.';
    }
    return;
}
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}
$browser = new BrowserSession($https);

$router = new Router();
require BASE_PATH . '/routes/web.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = $router->dispatch($method, $uri);
if ($method !== 'HEAD') {
    echo $body;
}
