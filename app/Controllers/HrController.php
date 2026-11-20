<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\Organization;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\HrManagement;
use Dtrms\Services\StaffWorkbook;

final class HrController
{
    public function __construct(
        private Organization $org,
        private HrManagement $hr,
        private BrowserSession $browser
    ) {
    }

    public function dashboard(array $actor): string
    {
        return $this->view($actor, 'dashboard', 'HR dashboard', ['metrics' => $this->org->metrics()]);
    }

    public function departments(array $actor): string
    {
        $search = self::text($_GET, 'q', 100);
        return $this->view($actor, 'departments', 'Departments', [
            'search' => $search, 'pagination' => $this->org->departments($search, self::page()),
        ]);
    }

    public function departmentForm(array $actor, ?string $error = null, ?array $values = null): string
    {
        $id = self::id($_GET);
        $department = $id ? $this->org->department($id) : null;
        if (($id || array_key_exists('id', $_GET)) && !$department) {
            return $this->missing();
        }
        $hodOptions = $id ? $this->org->hodOptions($id) : [];
        return $this->view($actor, 'department', $department ? 'Edit department' : 'Create department', [
            'department' => $department, 'values' => $values ?? $department ?? [],
            'hodOptions' => array_filter($hodOptions, static fn (array $user): bool => HrManagement::canManageRole($actor, $user['role_code'])
                || (int) ($department['hod_id'] ?? 0) === (int) $user['id']),
            'error' => $error,
        ]);
    }

    public function saveDepartment(array $actor): string
    {
        $id = self::id($_GET);
        if (array_key_exists('id', $_GET) && (!$id || !$this->org->department($id))) {
            return $this->missing();
        }
        $values = [
            'code' => self::text($_POST, 'code'), 'name' => self::text($_POST, 'name'),
            'hod_id' => self::id($_POST, 'hod_id'),
        ];
        try {
            if (($_POST['hod_id'] ?? '') !== '' && !$values['hod_id']) {
                throw new DomainException('Choose a valid HOD.');
            }
            $this->hr->saveDepartment($id, $values, $actor);
            return $this->success('/hr-manager/departments', $id ? 'Department updated.' : 'Department created.');
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->departmentForm($actor, $error->getMessage(), $values);
        }
    }

    public function departmentStatus(array $actor): string
    {
        $id = self::id($_GET);
        if (!$id || !$this->org->department($id)) {
            return $this->missing();
        }
        try {
            $active = self::statusInput();
            $this->hr->departmentStatus($id, $active, $actor);
            return $this->success('/hr-manager/departments', $active ? 'Department activated.' : 'Department deactivated.');
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->departmentForm($actor, $error->getMessage());
        }
    }

    public function directory(array $actor): string
    {
        $query = self::text($_GET, 'q', 100);
        $status = self::text($_GET, 'status');
        $role = self::text($_GET, 'role');
        $department = self::id($_GET, 'department');
        $roles = $this->roles($actor);
        $status = in_array($status, ['active', 'inactive'], true) ? $status : '';
        $role = isset($roles[$role]) ? $role : '';
        return $this->view($actor, 'directory', 'Staff directory', [
            'query' => $query, 'status' => $status, 'role' => $role, 'department' => $department,
            'roles' => $roles, 'departmentOptions' => $this->org->departmentOptions(),
            'pagination' => $this->org->directory($query, $status, $role, $department, self::page(), isset($roles['hr_officer'])),
        ]);
    }

    public function userForm(array $actor, ?string $error = null, ?array $values = null): string
    {
        $user = $this->target($actor);
        if (array_key_exists('id', $_GET) && !$user) {
            return $this->missing();
        }
        return $this->view($actor, 'user', $user ? 'Edit account' : 'Create account', [
            'user' => $user, 'values' => $values ?? $user ?? [], 'roles' => $this->roles($actor),
            'departmentOptions' => $this->org->departmentOptions(), 'error' => $error,
        ]);
    }

    public function saveUser(array $actor): string
    {
        $user = $this->target($actor);
        if (array_key_exists('id', $_GET) && !$user) {
            return $this->missing();
        }
        $input = [
            'staff_id' => self::text($_POST, 'staff_id'), 'name' => self::text($_POST, 'name'),
            'email' => self::text($_POST, 'email'), 'role' => self::text($_POST, 'role'),
            'department_id' => self::id($_POST, 'department_id'),
        ];
        try {
            if ($user) {
                $this->hr->editUser((int) $user['id'], $input, $actor);
                return $this->success('/hr-manager/staff/edit?id=' . $user['id'], 'Account details updated.');
            }
            $password = $this->hr->createUser($input + ['temporary_password' => $_POST['temporary_password'] ?? ''], $actor);
            return $this->credential($actor, 'Account created', $input['email'], $password);
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->userForm($actor, $error->getMessage(), $input);
        }
    }

    public function account(array $actor, ?string $error = null): string
    {
        $user = $this->target($actor);
        if (!$user) {
            return $this->missing();
        }
        return $this->view($actor, 'account', 'Account access', ['user' => $user, 'error' => $error]);
    }

    public function saveAccount(array $actor): string
    {
        $user = $this->target($actor);
        if (!$user) {
            return $this->missing();
        }
        $id = (int) $user['id'];
        try {
            $operation = self::text($_POST, 'operation');
            if ($operation === 'reset') {
                return $this->credential($actor, 'Password reset', $user['email'], $this->hr->resetPassword($id, $actor));
            }
            if ($operation === 'force') {
                $this->hr->requirePasswordChange($id, $actor);
                $message = 'Password change required at the next sign-in.';
            } else {
                $active = self::statusInput($operation);
                $this->hr->userStatus($id, $active, $actor);
                $message = $active ? 'Account activated.' : 'Account deactivated.';
            }
            return $this->success('/hr-manager/staff/access?id=' . $id, $message);
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->account($actor, $error->getMessage());
        }
    }

    public function assignment(array $actor, ?string $error = null, array $values = []): string
    {
        $user = $this->target($actor);
        if (!$user || $user['role_code'] !== 'staff') {
            return $this->missing();
        }
        return $this->view($actor, 'assignment', 'Supervisor assignment', [
            'user' => $user, 'error' => $error, 'values' => $values,
            'supervisorOptions' => $this->org->supervisorOptions(),
            'history' => $this->org->assignmentHistory((int) $user['id']),
        ]);
    }

    public function saveAssignment(array $actor): string
    {
        $user = $this->target($actor);
        if (!$user || $user['role_code'] !== 'staff') {
            return $this->missing();
        }
        $id = (int) $user['id'];
        $values = ['supervisor_id' => self::id($_POST, 'supervisor_id'), 'reason' => self::text($_POST, 'reason')];
        try {
            $this->hr->assignSupervisor($id, $values['supervisor_id'], $values['reason'], $actor);
            return $this->success('/hr-manager/staff/supervisor?id=' . $id, 'Supervisor assignment saved.');
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->assignment($actor, $error->getMessage(), $values);
        }
    }

    public function import(array $actor, ?string $error = null): string
    {
        return $this->view($actor, 'import', 'Import staff', ['available' => StaffWorkbook::available(), 'error' => $error]);
    }

    public function template(array $actor): string
    {
        if (!StaffWorkbook::available()) {
            http_response_code(503);
            return $this->import($actor, 'The Excel library is unavailable.');
        }
        $body = StaffWorkbook::template();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="dtrms-staff-template.xlsx"');
        return $body;
    }

    public function upload(array $actor): string
    {
        if (!StaffWorkbook::available()) {
            http_response_code(503);
            return $this->import($actor, 'The Excel library is unavailable.');
        }
        try {
            $file = $_FILES['workbook'] ?? [];
            if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_int($file['size']) || $file['size'] > StaffWorkbook::MAX_BYTES
                || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($file['tmp_name'])) {
                throw new DomainException('Choose an .xlsx workbook no larger than 1 MB.');
            }
            $count = $this->hr->importStaff(StaffWorkbook::read($file['tmp_name']), $actor);
            return $this->success('/hr-manager/staff', "$count staff accounts imported. Issue temporary passwords through Account access.");
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->import($actor, $error->getMessage());
        }
    }

    private function target(array $actor): ?array
    {
        $id = self::id($_GET);
        $user = $id ? $this->org->user($id) : null;
        return $user && HrManagement::canManageRole($actor, $user['role_code']) ? $user : null;
    }

    private function roles(array $actor): array
    {
        return array_filter(HrManagement::ROLES, static fn (string $role): bool => HrManagement::canManageRole($actor, $role), ARRAY_FILTER_USE_KEY);
    }

    private function view(array $actor, string $view, string $title, array $data = []): string
    {
        $message = $_SESSION['hr_notice'] ?? null;
        unset($_SESSION['hr_notice']);
        return View::render('hr/' . $view, $data + [
            'title' => $title, 'principal' => $actor, 'csrf' => $this->browser->csrf(), 'message' => $message, 'error' => null,
        ]);
    }

    private function credential(array $actor, string $title, string $email, string $password): string
    {
        return $this->view($actor, 'credential', $title, ['email' => $email, 'password' => $password]);
    }

    private function success(string $url, string $message): string
    {
        $_SESSION['hr_notice'] = $message;
        return Access::redirect($url);
    }

    private function missing(): string
    {
        http_response_code(404);
        return View::render('error', ['title' => 'Record unavailable', 'message' => 'This record could not be found.']);
    }

    private static function statusInput(?string $value = null): bool
    {
        $value ??= self::text($_POST, 'operation');
        return match ($value) {
            'activate' => true,
            'deactivate' => false,
            default => throw new DomainException('Choose a valid account or department status.'),
        };
    }

    private static function page(): int
    {
        return max(1, self::id($_GET, 'page'));
    }

    private static function id(array $source, string $key = 'id'): int
    {
        return (int) filter_var($source[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private static function text(array $source, string $key, int $limit = 2048): string
    {
        $value = $source[$key] ?? '';
        return is_string($value) && strlen($value) <= $limit && mb_check_encoding($value, 'UTF-8') ? trim($value) : '';
    }
}
