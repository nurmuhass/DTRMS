<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;

final class UserSessions
{
    public function __construct(private PDO $db, private int $idleMinutes = 30)
    {
    }

    public function create(int $userId, int $version, string $digest): void
    {
        $query = $this->db->prepare(
            'INSERT INTO user_sessions (token_hash, user_id, credential_version, expires_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP + INTERVAL 8 HOUR)'
        );
        $query->execute([$digest, $userId, $version]);
    }

    public function current(string $digest): ?array
    {
        $query = $this->db->prepare(
            'SELECT u.id, u.name, u.role_id, u.credential_version, u.must_change_password, r.code AS role_code
             FROM user_sessions s JOIN users u ON u.id = s.user_id JOIN roles r ON r.id = u.role_id
             WHERE s.token_hash = ? AND s.expires_at > CURRENT_TIMESTAMP
               AND s.last_seen_at > TIMESTAMPADD(MINUTE, ?, CURRENT_TIMESTAMP)
               AND u.is_active = TRUE AND u.credential_version = s.credential_version'
        );
        $query->execute([$digest, -$this->idleMinutes]);
        return $query->fetch() ?: null;
    }

    public function touch(string $digest): void
    {
        $query = $this->db->prepare(
            'UPDATE user_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE token_hash = ?'
        );
        $query->execute([$digest]);
    }

    public function remove(string $digest): void
    {
        $query = $this->db->prepare('DELETE FROM user_sessions WHERE token_hash = ?');
        $query->execute([$digest]);
    }

    public function revoke(int $userId): void
    {
        $query = $this->db->prepare('DELETE FROM user_sessions WHERE user_id = ?');
        $query->execute([$userId]);
    }
}
