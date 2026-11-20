<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;

final class UserAccount
{
    public function __construct(private PDO $db)
    {
    }

    public function byEmail(string $email): ?array
    {
        $query = $this->db->prepare('SELECT id, password_hash, is_active, credential_version, must_change_password
            FROM users WHERE email = ?');
        $query->execute([$email]);
        return $query->fetch() ?: null;
    }

    public function permissions(int $roleId): array
    {
        $query = $this->db->prepare(
            'SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?'
        );
        $query->execute([$roleId]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function replacePassword(int $userId, int $version, string $hash): bool
    {
        $query = $this->db->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = FALSE,
                credential_version = credential_version + 1, password_changed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND credential_version = ? AND is_active = TRUE'
        );
        $query->execute([$hash, $userId, $version]);
        return $query->rowCount() === 1;
    }

    public function audit(
        string $event,
        ?int $actor,
        ?int $subject,
        ?string $type = null,
        ?int $object = null,
        array $metadata = []
    ): void
    {
        $query = $this->db->prepare(
            'INSERT INTO audit_logs (event, actor_id, subject_user_id, object_type, object_id, metadata)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $query->execute([$event, $actor, $subject, $type, $object, $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }
}
