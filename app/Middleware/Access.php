<?php
declare(strict_types=1);

namespace Dtrms\Middleware;

use Dtrms\Core\HttpError;
use Dtrms\Services\Authentication;
use Dtrms\Services\BrowserSession;

final class Access
{
    public function __construct(private Authentication $auth, private BrowserSession $browser)
    {
    }

    public function protected(string $permission, callable $handler, bool $allowTemporary = false, string|array|null $role = null): callable
    {
        return function () use ($permission, $handler, $allowTemporary, $role): string {
            $principal = $this->auth->current();
            if ($principal === null) {
                return HttpError::wantsJson()
                    ? HttpError::response(401, 'authentication_required', 'Sign in to continue.')
                    : self::redirect('/login');
            }
            if (!self::permits($principal, $permission, $role)) {
                return self::denied('You do not have permission to open this page.');
            }
            if ((bool) $principal['must_change_password'] && !$allowTemporary) {
                return HttpError::wantsJson()
                    ? HttpError::response(403, 'password_change_required', 'Change your password to continue.')
                    : self::redirect('/change-password');
            }

            return $handler($principal);
        };
    }

    public static function permits(array $principal, string $permission, string|array|null $role = null): bool
    {
        return ($role === null || in_array($principal['role_code'], (array) $role, true))
            && in_array($permission, $principal['permissions'], true);
    }

    public function csrf(callable $handler): callable
    {
        return function (...$arguments) use ($handler): string {
            if (!$this->browser->validCsrf($_POST['csrf'] ?? null)) {
                return HttpError::response(403, 'invalid_csrf', 'This form has expired or is invalid. Reload the form and try again.');
            }
            return $handler(...$arguments);
        };
    }

    public static function redirect(string $path): string
    {
        header('Location: ' . $path, true, 303);
        return '';
    }

    private static function denied(string $message): string
    {
        return HttpError::response(403, 'forbidden', $message);
    }
}
