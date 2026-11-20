<?php
declare(strict_types=1);

namespace Dtrms\Services;

use Dtrms\Core\Database;
use Dtrms\Models\Notifications;
use Dtrms\Models\UserAccount;
use Dtrms\Models\UserSessions;
use PDO;

final class AccountRecovery
{
    public function __construct(private PDO $db)
    {
    }

    public function issue(string $email): ?string
    {
        return Database::transaction($this->db, function () use ($email): ?string {
            $query = $this->db->prepare('SELECT id FROM users WHERE email = ? AND is_active = TRUE FOR UPDATE');
            $query->execute([strtolower(trim($email))]);
            $id = (int) $query->fetchColumn();
            if (!$id) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $this->deleteToken($id);
            $insert = $this->db->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (?, ?, CURRENT_TIMESTAMP + INTERVAL 30 MINUTE)'
            );
            $insert->execute([$id, hash('sha256', $token)]);
            (new UserAccount($this->db))->audit('password_reset_issued', null, $id);
            (new Notifications($this->db))->publish($id, 'password_reset', null, 'helpdesk:' . $this->db->lastInsertId());
            return $token;
        });
    }

    public function redeem(string $token, string $password): bool
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $token) || !PasswordPolicy::acceptable($password)) {
            return false;
        }

        $digest = hash('sha256', $token);
        $query = $this->db->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash = ?');
        $query->execute([$digest]);
        $id = (int) $query->fetchColumn();
        if (!$id) {
            return false;
        }

        $hash = PasswordPolicy::hash($password);
        return Database::transaction($this->db, function () use ($id, $digest, $hash, $password): bool {
            // Lock in user-then-token order, matching issuance and password change.
            $account = $this->db->prepare('SELECT credential_version, password_hash FROM users WHERE id = ? AND is_active = TRUE FOR UPDATE');
            $account->execute([$id]);
            $user = $account->fetch();
            if (!$user || PasswordPolicy::verify($password, $user['password_hash'])) {
                return false;
            }

            $check = $this->db->prepare(
                'SELECT user_id FROM password_reset_tokens
                 WHERE user_id = ? AND token_hash = ? AND expires_at > CURRENT_TIMESTAMP FOR UPDATE'
            );
            $check->execute([$id, $digest]);
            if (!$check->fetchColumn()) {
                return false;
            }

            $users = new UserAccount($this->db);
            if (!$users->replacePassword($id, (int) $user['credential_version'], $hash)) {
                return false;
            }

            $this->deleteToken($id);
            (new UserSessions($this->db))->revoke($id);
            $users->audit('password_reset_completed', $id, $id);
            (new Notifications($this->db))->publish($id, 'password_reset_completed', null, (string) ((int) $user['credential_version'] + 1));
            return true;
        });
    }

    private function deleteToken(int $id): void
    {
        $delete = $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
        $delete->execute([$id]);
    }
}
