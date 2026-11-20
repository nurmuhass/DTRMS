<?php
declare(strict_types=1);

namespace Dtrms\Models;

use DomainException;
use Dtrms\Core\Database;
use PDO;
use PDOException;

final class AdministrativeRoles
{
    private const MANAGED = [
        'staff' => ['staff_workspace'],
        'supervisor' => ['supervisor_workspace'],
        'hr_officer' => ['hr_officer_workspace', 'hr_review_reports', 'hr_decide_reports', 'hr_export_reports'],
        'hr_manager' => ['hr_manager_workspace', 'hr_review_reports', 'hr_decide_reports', 'hr_manage_organization', 'hr_manage_officers', 'hr_export_reports'],
        'system_admin' => [],
    ];

    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        $roles = $this->db->query('SELECT id, code, name FROM roles ORDER BY id')->fetchAll();
        $query = $this->db->prepare('SELECT p.code, p.description, rp.permission_id IS NOT NULL AS granted
            FROM permissions p LEFT JOIN role_permissions rp ON rp.permission_id = p.id AND rp.role_id = ? ORDER BY p.code');
        foreach ($roles as &$role) {
            $query->execute([$role['id']]);
            $role['permissions'] = $query->fetchAll();
            $role['managed'] = self::MANAGED[$role['code']] ?? [];
        }
        return $roles;
    }

    public function update(int $actor, int $roleId, string $name, array $grants, string $version): void
    {
        $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new DomainException('Enter a valid role display name.');
        }

        try {
            Database::transaction($this->db, function () use ($actor, $roleId, $name, $grants, $version): void {
                $lock = $this->db->prepare('SELECT id, code, name FROM roles WHERE id = ? FOR UPDATE');
                $lock->execute([$roleId]);
                $role = $lock->fetch();
                if (!$role || !array_key_exists($role['code'], self::MANAGED)) {
                    throw new DomainException('The selected role is unavailable.', 404);
                }
                $allowed = self::MANAGED[$role['code']];
                foreach ($grants as $grant) {
                    if (!is_string($grant) || !in_array($grant, $allowed, true)) {
                        throw new DomainException('One or more permissions cannot be assigned to this role.', 422);
                    }
                }

                $query = $this->db->prepare('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                    WHERE rp.role_id = ? ORDER BY p.code');
                $query->execute([$roleId]);
                $current = $query->fetchAll(PDO::FETCH_COLUMN);
                if (!hash_equals(self::version($role['name'], $current), $version)) {
                    throw new DomainException('The role changed in another request. Reload before saving.', 409);
                }

                $grants = array_values(array_unique($grants));
                $added = array_diff($grants, $current);
                $removed = array_diff(array_intersect($current, $allowed), $grants);
                if (!$added && !$removed && $name === $role['name']) {
                    return;
                }
                $insert = $this->db->prepare('INSERT INTO role_permissions (role_id, permission_id)
                    SELECT ?, id FROM permissions WHERE code = ?');
                $delete = $this->db->prepare('DELETE FROM role_permissions WHERE role_id = ?
                    AND permission_id = (SELECT id FROM permissions WHERE code = ?)');
                foreach ($added as $grant) {
                    $insert->execute([$roleId, $grant]);
                }
                foreach ($removed as $grant) {
                    $delete->execute([$roleId, $grant]);
                }
                $rename = $this->db->prepare('UPDATE roles SET name = ? WHERE id = ?');
                $rename->execute([$name, $roleId]);
                (new UserAccount($this->db))->audit('role_permissions_updated', $actor, null, 'role', $roleId,
                    ['added' => array_values($added), 'removed' => array_values($removed), 'name_changed' => $name !== $role['name']]);
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('This role display name is already used.');
            }
            throw $error;
        }
    }

    public static function version(string $name, array $granted): string
    {
        sort($granted);
        return hash('sha256', json_encode([$name, $granted], JSON_THROW_ON_ERROR));
    }
}
