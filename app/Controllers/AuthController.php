<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Services\AccountRecovery;
use Dtrms\Services\Authentication;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\PasswordPolicy;

final class AuthController
{
    public function __construct(
        private Authentication $auth,
        private BrowserSession $browser,
        private AccountRecovery $recovery
    ) {
    }

    public function loginForm(): string
    {
        $principal = $this->auth->current();
        if ($principal !== null) {
            return Access::redirect($principal['must_change_password'] ? '/change-password' : '/');
        }
        return $this->form('login', 'Sign in');
    }

    public function login(): string
    {
        $existing = $this->auth->current();
        if ($existing !== null) {
            return Access::redirect($existing['must_change_password'] ? '/change-password' : '/');
        }

        $user = $this->auth->login(self::input('email'), self::input('password'), $_SERVER['REMOTE_ADDR'] ?? '');
        if ($user === null) {
            http_response_code(401);
            return $this->form('login', 'Sign in', 'Sign-in failed. Check your details or try again later.');
        }

        return Access::redirect($user['must_change_password'] ? '/change-password' : '/');
    }

    public function changeForm(array $principal): string
    {
        return $this->form('change-password', 'Change password', null, $principal);
    }

    public function change(array $principal): string
    {
        $password = self::input('password');
        if (!PasswordPolicy::acceptable($password)) {
            http_response_code(422);
            return $this->form('change-password', 'Change password', PasswordPolicy::MESSAGE, $principal);
        }
        if ($password !== self::input('confirmation')
            || !$this->auth->changePassword($principal, self::input('current_password'), $password)) {
            http_response_code(422);
            return $this->form(
                'change-password',
                'Change password',
                'Check your current password and choose a different password. The new entries must match.',
                $principal
            );
        }
        return Access::redirect('/login');
    }

    public function forgot(): string
    {
        // Identity verification and account lookup are performed by the authorized helpdesk.
        return View::render('auth/forgot', ['title' => 'Recover account']);
    }

    public function resetForm(): string
    {
        return $this->form('reset-password', 'Reset password');
    }

    public function reset(): string
    {
        $password = self::input('password');
        if (!PasswordPolicy::acceptable($password)) {
            http_response_code(422);
            return $this->form('reset-password', 'Reset password', PasswordPolicy::MESSAGE);
        }
        if ($password !== self::input('confirmation') || !$this->recovery->redeem(trim(self::input('code')), $password)) {
            http_response_code(422);
            return $this->form('reset-password', 'Reset password', 'The reset could not be completed. Check the code and password entries.');
        }

        $this->auth->logout();
        return Access::redirect('/login');
    }

    public function logout(): string
    {
        $this->auth->logout();
        return Access::redirect('/login');
    }

    private function form(string $view, string $title, ?string $error = null, ?array $principal = null): string
    {
        return View::render('auth/' . $view, [
            'title' => $title,
            'error' => $error,
            'csrf' => $this->browser->csrf(),
            'passwordRule' => PasswordPolicy::MESSAGE,
            'passwordMinimum' => PasswordPolicy::MINIMUM,
            'principal' => $principal,
        ]);
    }

    private static function input(string $name): string
    {
        $value = $_POST[$name] ?? '';
        return is_string($value) ? $value : '';
    }
}
