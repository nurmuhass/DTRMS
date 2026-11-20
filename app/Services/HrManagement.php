<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;
use Dtrms\Core\Database;
use Dtrms\Middleware\Access;
use Dtrms\Models\Organization;
use Dtrms\Models\Notifications;
use Dtrms\Models\UserAccount;
use Dtrms\Models\UserSessions;
use PDO;
use PDOException;

final class HrManagement
{
    public const ROLES = ['staff' => 'Staff', 'supervisor' => 'Supervisor', 'hr_officer' => 'HR Officer'];

    public function __construct(private PDO $db, private Organization $org)
    {
    }

    public static function canManageRole(array $actor, string $role): bool
    {
        return isset(self::ROLES[$role]) && ($role !== 'hr_officer' || Access::permits($actor, 'hr_manage_officers'));
    }

    public function saveDepartment(int $id, array $input, array $actor): void
    {
        $code = self::code($input['code'], 'Department code');
        $name = self::required($input['name'], 150, 'Department name');
        $hod = $input['hod_id'];
        $this->write(function () use ($id, $code, $name, $hod, $actor): void {
            $department = $id ? $this->org->department($id) : null;
            if ($id && !$department) {
                throw new DomainException('Department no longer exists.');
            }
            if ($hod && $hod !== (int) ($department['hod_id'] ?? 0)) {
                $candidate = $this->org->user($hod);
                if (!$department || !$department['is_active'] || !$candidate || !$candidate['is_active']
                    || (int) $candidate['department_id'] !== $id || !self::canManageRole($actor, $candidate['role_code'])) {
                    throw new DomainException('Choose an active, authorized member of this department as HOD.');
                }
            }

            if ($department) {
                $this->org->query('UPDATE departments SET code = ?, name = ?, hod_id = ? WHERE id = ?', [$code, $name, $hod ?: null, $id]);
                $this->audit('department_updated', $actor, null, 'department', $id, [
                    'previous_hod_id' => $department['hod_id'], 'hod_id' => $hod ?: null,
                ]);
            } else {
                $this->org->query('INSERT INTO departments (code, name) VALUES (?, ?)', [$code, $name]);
                $this->audit('department_created', $actor, null, 'department', (int) $this->db->lastInsertId());
            }
        }, 'The department code or name is already in use.');
    }

    public function departmentStatus(int $id, bool $active, array $actor): void
    {
        $this->write(function () use ($id, $active, $actor): void {
            $department = $this->org->department($id);
            if (!$department) {
                throw new DomainException('Department no longer exists.');
            }
            if ((bool) $department['is_active'] === $active) {
                return;
            }
            if (!$active && ($department['hod_id'] || $this->org->query(
                'SELECT 1 FROM users WHERE department_id = ? AND is_active LIMIT 1', [$id]
            )->fetchColumn())) {
                throw new DomainException('Clear the HOD and move or deactivate active members before deactivating this department.');
            }
            $this->org->query('UPDATE departments SET is_active = ? WHERE id = ?', [(int) $active, $id]);
            $this->audit($active ? 'department_activated' : 'department_deactivated', $actor, null, 'department', $id);
        });
    }

    public function createUser(array $input, array $actor): string
    {
        if (!self::canManageRole($actor, $input['role'])) {
            throw new DomainException('Choose an authorized organizational role.');
        }
        $person = $this->validatePerson($input);
        $password = $input['temporary_password'] ?? '';
        if ($password === '') {
            $password = PasswordPolicy::temporary();
        } elseif (!is_string($password) || !PasswordPolicy::acceptable($password)) {
            throw new DomainException(PasswordPolicy::MESSAGE);
        }
        $hash = PasswordPolicy::hash($password);
        $this->write(fn () => $this->insertUser($person, $input['role'], $hash, $actor), 'The staff ID or email is already in use.');

        return $password;
    }

    public function editUser(int $id, array $input, array $actor): void
    {
        $person = $this->validatePerson($input);
        $this->write(function () use ($id, $person, $actor): void {
            $user = $this->target($id, $actor);
            if ($person['department'] !== (int) $user['department_id']) {
                $this->activeDepartment($person['department']);
                $this->ensureNotHod($id);
            }
            $this->org->query('UPDATE users SET staff_id = ?, name = ?, email = ?, department_id = ? WHERE id = ?', [
                $person['staffId'], $person['name'], $person['email'], $person['department'], $id,
            ]);
            if ($person['email'] !== $user['email']) {
                $this->org->query('UPDATE users SET credential_version = credential_version + 1 WHERE id = ?', [$id]);
                $this->revokeCredentials($id);
            }
            $this->audit('account_updated', $actor, $id, 'user', $id, [
                'previous_staff_id' => $user['staff_id'], 'staff_id' => $person['staffId'],
                'previous_department_id' => $user['department_id'], 'department_id' => $person['department'],
                'email_changed' => $person['email'] !== $user['email'],
            ]);
        }, 'The staff ID or email is already in use.');
    }

    public function userStatus(int $id, bool $active, array $actor): void
    {
        $this->write(function () use ($id, $active, $actor): void {
            $user = $this->target($id, $actor);
            if ((bool) $user['is_active'] === $active) {
                return;
            }
            if ($active) {
                $this->activeDepartment((int) $user['department_id']);
                if (!$user['staff_id']) {
                    throw new DomainException('Set a staff ID before reactivating this account.');
                }
            } else {
                $this->ensureNotHod($id);
                if ($this->org->query('SELECT 1 FROM supervisor_assignments WHERE supervisor_id = ? AND ended_at IS NULL LIMIT 1', [$id])->fetchColumn()) {
                    throw new DomainException('Reassign this supervisor’s staff before deactivating the account.');
                }
            }
            $this->org->query('UPDATE users SET is_active = ?, credential_version = credential_version + 1 WHERE id = ?', [(int) $active, $id]);
            $this->revokeCredentials($id);
            $this->audit($active ? 'account_reactivated' : 'account_deactivated', $actor, $id, 'user', $id);
            if ($active) {
                (new Notifications($this->db))->publish($id, 'account_reactivated', null, $this->db->lastInsertId());
            }
        });
    }

    public function resetPassword(int $id, array $actor): string
    {
        $password = PasswordPolicy::temporary();
        $hash = PasswordPolicy::hash($password);
        $this->requirePasswordChange($id, $actor, $hash);
        return $password;
    }

    public function requirePasswordChange(int $id, array $actor, ?string $hash = null): void
    {
        $this->write(function () use ($id, $actor, $hash): void {
            $user = $this->target($id, $actor);
            if (!$user['is_active']) {
                throw new DomainException('Reactivate the account before changing its credentials.');
            }
            if ($hash === null) {
                $this->org->query('UPDATE users SET must_change_password = TRUE, credential_version = credential_version + 1 WHERE id = ?', [$id]);
            } else {
                $this->org->query('UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP,
                    must_change_password = TRUE, credential_version = credential_version + 1 WHERE id = ?', [$hash, $id]);
            }
            $this->revokeCredentials($id);
            $this->audit($hash === null ? 'password_change_required' : 'password_reset_by_hr', $actor, $id, 'user', $id);
            (new Notifications($this->db))->publish($id, $hash === null ? 'password_change_required' : 'password_reset',
                null, 'hr:' . $this->db->lastInsertId());
        });
    }

    public function assignSupervisor(int $id, int $supervisor, string $reason, array $actor): void
    {
        $reason = self::required($reason, 500, 'Reason');
        $this->write(function () use ($id, $supervisor, $reason, $actor): void {
            $staff = $this->target($id, $actor);
            $new = $this->org->user($supervisor);
            if ($staff['role_code'] !== 'staff' || !$new || !$new['is_active'] || $new['role_code'] !== 'supervisor' || $id === $supervisor) {
                throw new DomainException('Choose an active supervisor for a staff account.');
            }
            $previous = $staff['supervisor_id'] ? (int) $staff['supervisor_id'] : null;
            if ($previous === $supervisor) {
                throw new DomainException('This supervisor is already assigned.');
            }
            if ($previous !== null) {
                $this->org->query('UPDATE supervisor_assignments SET ended_at = CURRENT_TIMESTAMP WHERE current_staff_id = ?', [$id]);
            }
            $this->org->query('INSERT INTO supervisor_assignments
                (staff_id, supervisor_id, previous_supervisor_id, assigned_by, change_reason)
                VALUES (?, ?, ?, ?, ?)', [$id, $supervisor, $previous, $actor['id'], $reason]);
            (new Notifications($this->db))->reassignment($id, $supervisor, (int) $this->db->lastInsertId());
            $this->audit('supervisor_assignment_changed', $actor, $id, 'user', $id, [
                'staff_id' => $staff['staff_id'], 'previous_supervisor_id' => $previous,
                'new_supervisor_id' => $supervisor, 'reason' => $reason,
            ]);
        });
    }

    public function importStaff(array $rows, array $actor): int
    {
        // Imported accounts receive individually generated passwords through the HR reset action.
        $placeholderHash = PasswordPolicy::hash(bin2hex(random_bytes(32)));
        return $this->write(function () use ($rows, $actor, $placeholderHash): int {
            $departments = [];
            foreach ($this->org->departmentOptions() as $department) {
                if ($department['is_active']) {
                    $departments[strtoupper($department['code'])] = (int) $department['id'];
                }
            }
            $ids = [];
            $emails = [];
            foreach ($rows as $line => [$staffId, $name, $email, $departmentCode]) {
                try {
                    $code = self::code($departmentCode, 'Department code');
                    $person = $this->validatePerson([
                        'staff_id' => $staffId, 'name' => $name, 'email' => $email,
                        'department_id' => $departments[$code] ?? 0,
                    ]);
                    if (isset($ids[$person['staffId']]) || isset($emails[$person['email']])) {
                        throw new DomainException('Duplicate staff ID or email in this workbook.');
                    }
                    $ids[$person['staffId']] = $emails[$person['email']] = true;
                    $this->insertUser($person, 'staff', $placeholderHash, $actor);
                } catch (DomainException | PDOException $error) {
                    if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) !== 1062) {
                        throw $error;
                    }
                    $message = $error instanceof PDOException ? 'The staff ID or email is already in use.' : $error->getMessage();
                    throw new DomainException("Row $line. " . $message, 0, $error);
                }
            }
            return count($rows);
        });
    }

    private function insertUser(array $person, string $role, string $hash, array $actor): void
    {
        $this->activeDepartment($person['department']);
        $this->org->query('INSERT INTO users (staff_id, name, email, department_id, role_id, password_hash)
            VALUES (?, ?, ?, ?, (SELECT id FROM roles WHERE code = ?), ?)', [
            $person['staffId'], $person['name'], $person['email'], $person['department'], $role, $hash,
        ]);
        $id = (int) $this->db->lastInsertId();
        (new Notifications($this->db))->publish($id, 'account_created', null, 'created');
        $this->audit('account_created', $actor, $id, 'user', $id, ['staff_id' => $person['staffId'], 'role' => $role]);
    }

    private function target(int $id, array $actor): array
    {
        $this->org->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id])->fetchColumn();
        $target = $this->org->user($id);
        if (!$target || !self::canManageRole($actor, $target['role_code'])) {
            throw new DomainException('This organizational account is unavailable.');
        }
        return $target;
    }

    private function validatePerson(array $input): array
    {
        $staffId = self::staffId($input['staff_id']);
        $name = self::required($input['name'], 150, 'Full name');
        $email = strtolower(trim($input['email']));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid institutional email address.');
        }
        $department = $input['department_id'];
        if (!$department) {
            throw new DomainException('Choose an active department.');
        }
        return compact('staffId', 'name', 'email', 'department');
    }

    private function activeDepartment(int $id): void
    {
        $department = $this->org->department($id);
        if (!$department || !$department['is_active']) {
            throw new DomainException('Choose an active department.');
        }
    }

    private function ensureNotHod(int $id): void
    {
        if ($this->org->query('SELECT 1 FROM departments WHERE hod_id = ? LIMIT 1', [$id])->fetchColumn()) {
            throw new DomainException('Clear or replace the HOD assignment before moving or deactivating this account.');
        }
    }

    private function revokeCredentials(int $id): void
    {
        $this->org->query('DELETE FROM password_reset_tokens WHERE user_id = ?', [$id]);
        (new UserSessions($this->db))->revoke($id);
    }

    private function audit(string $event, array $actor, ?int $subject, string $type, int $object, array $metadata = []): void
    {
        (new UserAccount($this->db))->audit($event, (int) $actor['id'], $subject, $type, $object, $metadata);
    }

    private function write(callable $operation, string $duplicate = 'The submitted entry conflicts with an existing record.'): mixed
    {
        // Department activation, HOD membership, and supervisor coverage span several tables.
        $schema = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        $lock = hash('sha256', 'dtrms:organization:' . $schema);
        if ((int) $this->org->query('SELECT GET_LOCK(?, 5)', [$lock])->fetchColumn() !== 1) {
            throw new DomainException('Another organization change is in progress. Try again.');
        }
        try {
            return Database::transaction($this->db, $operation);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException($duplicate, 0, $error);
            }
            throw $error;
        } finally {
            $this->org->query('SELECT RELEASE_LOCK(?)', [$lock])->fetchColumn();
        }
    }

    public static function staffId(string $value): string
    {
        return self::code($value, 'Staff ID');
    }

    private static function code(string $value, string $label): string
    {
        $value = strtoupper(trim($value));
        if (!preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{0,39}\z/', $value)) {
            throw new DomainException("$label must be 1 to 40 ASCII letters, digits, periods, underscores, slashes, or hyphens.");
        }
        return $value;
    }

    private static function required(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new DomainException("$label must contain 1 to $max characters without control characters.");
        }
        return $value;
    }
}
