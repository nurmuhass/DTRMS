<?php
declare(strict_types=1);

namespace Dtrms\Services;

final class PasswordPolicy
{
    public const MINIMUM = 8;
    public const MESSAGE = 'Password must contain at least 8 characters.';
    private const PREFIX = 'dtrms-sha384:';

    public static function acceptable(string $password): bool
    {
        return mb_check_encoding($password, 'UTF-8') && mb_strlen($password, 'UTF-8') >= self::MINIMUM;
    }

    public static function verify(string $password, string $hash): bool
    {
        if (str_starts_with($hash, self::PREFIX)) {
            return password_verify(self::digest($password), substr($hash, strlen(self::PREFIX)));
        }

        // Legacy bcrypt hashes were created only from non-empty inputs of at most 72 bytes.
        $valid = $password !== '' && strlen($password) <= 72 && !str_contains($password, "\0");
        return password_verify($valid ? $password : '', $hash) && $valid;
    }

    public static function hash(string $password): string
    {
        return self::PREFIX . password_hash(self::digest($password), PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function temporary(): string
    {
        return bin2hex(random_bytes(12));
    }

    private static function digest(string $password): string
    {
        // An encoded 48-byte digest fits bcrypt without truncating any part of the password.
        return base64_encode(hash('sha384', "DTRMS password v1\0" . $password, true));
    }
}
