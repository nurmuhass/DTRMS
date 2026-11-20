<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\AdministrativeRoles;
use Dtrms\Models\SystemConfiguration;
use Dtrms\Models\TechnicalMonitoring;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\OperationalPolicy;

final class AdminController
{
    private const LOGO_LIMIT = 262144;

    public function __construct(
        private SystemConfiguration $settings,
        private AdministrativeRoles $roles,
        private TechnicalMonitoring $monitoring,
        private BrowserSession $browser
    ) {
    }

    public function dashboard(array $actor): string
    {
        return $this->view($actor, 'dashboard', 'System administration', $this->monitoring->dashboard());
    }

    public function settings(array $actor, ?string $error = null): string
    {
        $values = $this->settings->configuration();
        return $this->view($actor, 'settings', 'System settings', [
            'values' => $values, 'version' => SystemConfiguration::version($values),
            'branding' => $this->settings->branding($values), 'error' => $error,
            'days' => OperationalPolicy::DAYS,
        ]);
    }

    public function saveSettings(array $actor): string
    {
        try {
            $version = $_POST['version'] ?? '';
            $this->settings->update((int) $actor['id'], OperationalPolicy::validate($_POST), is_string($version) ? $version : '');
            return $this->success('/administration/settings', 'System settings saved.');
        } catch (DomainException $error) {
            http_response_code($error->getCode() === 409 ? 409 : 422);
            return $this->settings($actor, $error->getMessage());
        }
    }

    public function saveLogo(array $actor): string
    {
        try {
            $action = $_POST['action'] ?? '';
            $image = match ($action) {
                'remove' => null,
                'upload' => $this->uploadedPng(),
                default => throw new DomainException('Choose to upload or remove the organization logo.'),
            };
            $this->settings->updateLogo((int) $actor['id'], $image);
            return $this->success('/administration/settings', $image === null ? 'Organization logo removed.' : 'Organization logo updated.');
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->settings($actor, $error->getMessage());
        }
    }

    public function logo(): string
    {
        $logo = $this->settings->logo();
        if ($logo === null) {
            http_response_code(404);
            return '';
        }
        header('Content-Type: image/png');
        return $logo;
    }

    public function roles(array $actor, ?string $error = null): string
    {
        $roles = $this->roles->all();
        $id = self::positive($_GET['role'] ?? null);
        $selected = $roles[0] ?? null;
        foreach ($roles as $role) {
            if ((int) $role['id'] === $id) {
                $selected = $role;
                break;
            }
        }
        $granted = array_column(array_filter($selected['permissions'] ?? [], static fn (array $permission): bool => (bool) $permission['granted']), 'code');
        return $this->view($actor, 'roles', 'Roles and permissions', [
            'roles' => $roles, 'selected' => $selected, 'version' => AdministrativeRoles::version($selected['name'] ?? '', $granted),
            'error' => $error,
        ]);
    }

    public function saveRole(array $actor): string
    {
        $role = self::positive($_GET['role'] ?? null);
        try {
            $name = $_POST['name'] ?? '';
            $version = $_POST['version'] ?? '';
            $grants = $_POST['permissions'] ?? [];
            if (!$role || !is_string($name) || !is_string($version) || !is_array($grants) || count($grants) > 40) {
                throw new DomainException('The role form contains an invalid value.');
            }
            $this->roles->update((int) $actor['id'], $role, $name, $grants, $version);
            return $this->success('/administration/roles?role=' . $role, 'Role configuration saved.');
        } catch (DomainException $error) {
            $status = in_array($error->getCode(), [404, 409], true) ? $error->getCode() : 422;
            http_response_code($status);
            return $this->roles($actor, $error->getMessage());
        }
    }

    public function monitor(array $actor, string $section): string
    {
        $title = TechnicalMonitoring::SECTIONS[$section];
        $values = [];
        if ($section === 'database') {
            $values['connection'] = $this->monitoring->connectivity();
        } else {
            $values['listing'] = $this->monitoring->page($section, self::positive($_GET['page'] ?? null) ?: 1);
        }
        if ($section === 'login') {
            $values['suspicious'] = $this->monitoring->suspicious();
        }
        if ($section === 'backups') {
            $values['settings'] = $this->settings->configuration();
            $values['backup'] = $this->monitoring->backupHealth();
        }
        return $this->view($actor, 'monitor', $title, $values + ['section' => $section, 'sections' => TechnicalMonitoring::SECTIONS]);
    }

    private function uploadedPng(): string
    {
        $file = $_FILES['logo'] ?? [];
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_int($file['size']) || $file['size'] > self::LOGO_LIMIT
            || !is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('Choose a PNG, JPEG, or WebP image up to 256 KB.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new DomainException('The PHP GD extension is needed to process an organization logo.');
        }
        $original = file_get_contents($file['tmp_name'], false, null, 0, self::LOGO_LIMIT + 1);
        $info = $original !== false ? @getimagesizefromstring($original) : false;
        if (!$info || max($info[0], $info[1]) > 1024 || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new DomainException('Use a PNG, JPEG, or WebP image no larger than 1024 × 1024 pixels.');
        }
        $image = @imagecreatefromstring($original);
        if (!$image) {
            throw new DomainException('The organization logo could not be decoded.');
        }
        imagesavealpha($image, true);
        ob_start();
        try {
            imagepng($image);
            $png = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if ($png === '' || strlen($png) > self::LOGO_LIMIT) {
            throw new DomainException('Choose a smaller organization logo.');
        }
        return $png;
    }

    private function view(array $actor, string $view, string $title, array $data = []): string
    {
        $message = $_SESSION['admin_notice'] ?? null;
        unset($_SESSION['admin_notice']);
        return View::render('admin/' . $view, $data + [
            'title' => $title, 'principal' => $actor, 'csrf' => $this->browser->csrf(), 'message' => $message, 'error' => null,
        ]);
    }

    private function success(string $url, string $message): string
    {
        $_SESSION['admin_notice'] = $message;
        return Access::redirect($url);
    }

    private static function positive(mixed $value): int
    {
        return (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
}
