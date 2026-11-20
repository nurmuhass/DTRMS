<?php
declare(strict_types=1);

namespace Dtrms\Services;

use Dtrms\Core\Database;
use Dtrms\Models\LoginAttempts;
use Dtrms\Models\Notifications;
use Dtrms\Models\UserAccount;
use Dtrms\Models\UserSessions;
use PDO;

final class Authentication
{
    // A bcrypt hash used only to keep the verification path uniform for unknown users.
    private const DUMMY_HASH = '$2y$12$lKAm4Y/MjYiLVoWtPHFxDeXv4AAKklMcSFir8vrGHYa6EoVdETCWO';

    private UserAccount $users;
    private UserSessions $sessions;
    private LoginAttempts $attempts;

    public function __construct(
        private PDO $db,
        private BrowserSession $browser,
        int $idleMinutes = 30
    )
    {
        $this->users = new UserAccount($db);
        $this->sessions = new UserSessions($db, $idleMinutes);
        $this->attempts = new LoginAttempts($db);
    }

    public function current(): ?array
    {
        $digest = $this->browser->digest();
        if ($digest === null) {
            return null;
        }

        $user = $this->sessions->current($digest);
        if ($user === null) {
            $this->browser->forgetAuthentication();
            return null;
        }

        $this->sessions->touch($digest);
        $user['permissions'] = $this->users->permissions((int) $user['role_id']);
        return $user;
    }

    public function login(string $email, string $password, string $remoteAddress): ?array
    {
        $email = strtolower(trim($email));
        $identifier = hash('sha256', $email);
        $packed = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : false;
        $ip = $packed !== false ? inet_ntop($packed) : 'unknown';

        $attempt = $this->attempts->record($identifier, $ip);
        if ($this->attempts->throttled($identifier, $ip)) {
            $this->attempts->markBlocked($attempt);
            return null;
        }

        $user = strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $this->users->byEmail($email) : null;
        $verified = PasswordPolicy::verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if ($user === null || !$verified || !(bool) $user['is_active']) {
            return null;
        }

        $id = (int) $user['id'];
        $version = (int) $user['credential_version'];
        $secret = bin2hex(random_bytes(32));
        $oldDigest = $this->browser->digest();
        $created = Database::transaction($this->db, function () use ($id, $version, $attempt, $secret, $oldDigest): bool {
            $lock = $this->db->prepare('SELECT id FROM users WHERE id = ? AND credential_version = ? AND is_active = TRUE FOR UPDATE');
            $lock->execute([$id, $version]);
            if (!$lock->fetchColumn()) {
                return false;
            }

            $update = $this->db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
            $update->execute([$id]);
            $this->sessions->create($id, $version, hash('sha256', $secret));
            if ($oldDigest !== null) {
                $this->sessions->remove($oldDigest);
            }
            $this->attempts->markSucceeded($attempt);
            return true;
        });
        if (!$created) {
            return null;
        }

        $this->browser->establish($secret);
        return $user;
    }

    public function logout(): void
    {
        $digest = $this->browser->digest();
        if ($digest !== null) {
            $this->sessions->remove($digest);
        }
        $this->browser->clear();
    }

    public function changePassword(array $principal, string $current, string $next): bool
    {
        $id = (int) $principal['id'];
        $version = (int) $principal['credential_version'];
        $query = $this->db->prepare('SELECT password_hash FROM users WHERE id = ? AND credential_version = ?');
        $query->execute([$id, $version]);
        $oldHash = $query->fetchColumn();
        if (!$oldHash || !PasswordPolicy::verify($current, $oldHash)
            || !PasswordPolicy::acceptable($next) || PasswordPolicy::verify($next, $oldHash)) {
            return false;
        }

        $hash = PasswordPolicy::hash($next);
        $changed = Database::transaction($this->db, function () use ($id, $version, $hash): bool {
            if (!$this->users->replacePassword($id, $version, $hash)) {
                return false;
            }

            $tokens = $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
            $tokens->execute([$id]);
            $this->sessions->revoke($id);
            $this->users->audit('password_changed', $id, $id);
            (new Notifications($this->db))->publish($id, 'password_changed', null, (string) ($version + 1));
            return true;
        });

        if ($changed) {
            $this->browser->clear();
        }
        return $changed;
    }
}
