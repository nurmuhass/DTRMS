<?php
declare(strict_types=1);

namespace Dtrms\Services;

use RuntimeException;

final class BrowserSession
{
    public function __construct(bool $secure)
    {
        session_name($secure ? '__Host-DTRMSSESSID' : 'DTRMSSESSID');
        session_start([
            'save_path' => BASE_PATH . '/storage/sessions',
            'gc_maxlifetime' => 28800,
            'use_strict_mode' => 1,
            'use_cookies' => 1,
            'use_only_cookies' => 1,
            'use_trans_sid' => 0,
            'cookie_secure' => $secure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_path' => '/',
            'cookie_domain' => '',
            'cookie_lifetime' => 0,
        ]);
    }

    public function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function validCsrf(mixed $value): bool
    {
        return is_string($value) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $value);
    }

    public function digest(): ?string
    {
        return isset($_SESSION['authentication']) ? hash('sha256', $_SESSION['authentication']) : null;
    }

    public function forgetAuthentication(): void
    {
        unset($_SESSION['authentication'], $_SESSION['csrf']);
    }

    public function establish(string $secret): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not regenerate browser session.');
        }
        $_SESSION = ['authentication' => $secret];
    }

    public function clear(): void
    {
        $_SESSION = [];
        $options = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $options['path'],
            'domain' => $options['domain'],
            'secure' => $options['secure'],
            'httponly' => $options['httponly'],
            'samesite' => $options['samesite'],
        ]);
        session_destroy();
    }
}
