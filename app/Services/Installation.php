<?php
declare(strict_types=1);

namespace Dtrms\Services;

use Dtrms\Core\Database;
use Dtrms\Models\Notifications;
use Dtrms\Models\UserAccount;
use DomainException;
use PDO;
use PDOException;
use RuntimeException;

final class Installation
{
    public function __construct(private PDO $db)
    {
    }

    public function initialize(string $name, string $email, string $password): bool
    {
        [$name, $email] = $this->identity($name, $email, $password);

        return Database::transaction($this->db, function () use ($name, $email, $password): bool {
            $state = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'installation_initialized' FOR UPDATE"
            )->fetchColumn();
            if ($state === '1') {
                return false;
            }
            if ($state !== '0') {
                throw new RuntimeException('Installation state is missing or invalid.');
            }

            $roleId = $this->db->query("SELECT id FROM roles WHERE code = 'system_admin'")->fetchColumn();
            if (!$roleId) {
                throw new RuntimeException('Administrator role is missing.');
            }

            $insert = $this->db->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (?, ?, ?, ?)');
            $insert->execute([$roleId, $name, $email, PasswordPolicy::hash($password)]);
            $id = (int) $this->db->lastInsertId();
            (new UserAccount($this->db))->audit('installation_initialized', null, $id);
            (new Notifications($this->db))->publish($id, 'account_created', null, 'created');

            $complete = $this->db->prepare(
                "UPDATE system_settings SET setting_value = '1', updated_by = ? WHERE setting_key = 'installation_initialized'"
            );
            $complete->execute([$id]);
            return true;
        });
    }

    public function provisionHrManager(string $staffId, string $name, string $email, string $password): void
    {
        [$name, $email] = $this->identity($name, $email, $password);
        $staffId = HrManagement::staffId($staffId);
        $hash = PasswordPolicy::hash($password);
        try {
            Database::transaction($this->db, function () use ($staffId, $name, $email, $hash): void {
                $state = $this->db->query(
                    "SELECT setting_value FROM system_settings WHERE setting_key = 'installation_initialized'"
                )->fetchColumn();
                if ($state !== '1') {
                    throw new DomainException('Initialize the first System Administrator before provisioning an HR Manager.');
                }
                $insert = $this->db->prepare(
                    "INSERT INTO users (staff_id, name, email, role_id, password_hash)
                     SELECT ?, ?, ?, id, ? FROM roles WHERE code = 'hr_manager'"
                );
                $insert->execute([$staffId, $name, $email, $hash]);
                if ($insert->rowCount() !== 1) {
                    throw new DomainException('Seed the HR Manager role before provisioning the account.');
                }
                $id = (int) $this->db->lastInsertId();
                (new UserAccount($this->db))->audit('account_created', null, $id, 'user', $id, [
                    'staff_id' => $staffId, 'role' => 'hr_manager', 'source' => 'authorized_console',
                ]);
                (new Notifications($this->db))->publish($id, 'account_created', null, 'created');
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('The staff ID or email is already in use.', 0, $error);
            }
            throw $error;
        }
    }

    private function identity(string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || preg_match('//u', $name) !== 1 || mb_strlen($name, 'UTF-8') > 150
            || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
           ) {
            throw new \InvalidArgumentException('Supply a valid name and institutional email.');
        }
        if (!PasswordPolicy::acceptable($password)) {
            throw new \InvalidArgumentException(PasswordPolicy::MESSAGE);
        }
        return [$name, $email];
    }
}
