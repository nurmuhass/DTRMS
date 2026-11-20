<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;

final class LoginAttempts
{
    public function __construct(private PDO $db)
    {
    }

    public function record(string $identifier, string $ip): int
    {
        $query = $this->db->prepare('INSERT INTO login_attempts (identifier_hash, ip_address) VALUES (?, ?)');
        $query->execute([$identifier, $ip]);
        return (int) $this->db->lastInsertId();
    }

    public function throttled(string $identifier, string $ip): bool
    {
        $query = $this->db->prepare(
            'SELECT
                (SELECT COUNT(*) FROM login_attempts WHERE identifier_hash = ? AND succeeded = FALSE AND blocked = FALSE
                  AND attempted_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE) AS identifier_count,
                (SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND succeeded = FALSE AND blocked = FALSE
                  AND attempted_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE) AS ip_count'
        );
        $query->execute([$identifier, $ip]);
        $counts = $query->fetch();
        return (int) $counts['identifier_count'] > 8 || (int) $counts['ip_count'] > 30;
    }

    public function markBlocked(int $id): void
    {
        $query = $this->db->prepare('UPDATE login_attempts SET blocked = TRUE WHERE id = ?');
        $query->execute([$id]);
    }

    public function markSucceeded(int $id): void
    {
        $query = $this->db->prepare('UPDATE login_attempts SET succeeded = TRUE WHERE id = ?');
        $query->execute([$id]);
    }
}
