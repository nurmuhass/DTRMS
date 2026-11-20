<?php
declare(strict_types=1);

namespace Dtrms\Models;

use DomainException;
use Dtrms\Core\Database;
use Dtrms\Services\OperationalPolicy;
use PDO;

final class SystemConfiguration
{
    private const KEY = 'operational_configuration';

    public function __construct(private PDO $db)
    {
    }

    public function configuration(): array
    {
        $query = $this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $query->execute([self::KEY]);
        return $this->decode($query->fetchColumn());
    }

    public function branding(array $values): array
    {
        return [
            'app_name' => $values['app_name'], 'organization_name' => $values['organization_name'],
            'has_logo' => (bool) $this->db->query('SELECT 1 FROM application_branding WHERE id = 1 AND logo IS NOT NULL')->fetchColumn(),
        ];
    }

    public function logo(): ?string
    {
        $value = $this->db->query('SELECT logo FROM application_branding WHERE id = 1')->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function update(int $actor, array $values, string $version): void
    {
        Database::transaction($this->db, function () use ($actor, $values, $version): void {
            $lock = $this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? FOR UPDATE');
            $lock->execute([self::KEY]);
            $current = $this->decode($lock->fetchColumn());
            if (!hash_equals(self::version($current), $version)) {
                throw new DomainException('Settings changed in another request. Reload before saving.', 409);
            }
            $changes = [];
            foreach ($values as $field => $value) {
                if ($value !== $current[$field]) {
                    $changes[] = $field;
                }
            }
            if (!$changes) {
                return;
            }
            $update = $this->db->prepare('UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?');
            $update->execute([json_encode($values, JSON_THROW_ON_ERROR), $actor, self::KEY]);
            (new UserAccount($this->db))->audit('system_configuration_updated', $actor, null, 'system_configuration', null,
                ['fields' => $changes]);
        });
    }

    public function updateLogo(int $actor, ?string $image): void
    {
        Database::transaction($this->db, function () use ($actor, $image): void {
            $update = $this->db->prepare('UPDATE application_branding SET logo = ?, updated_by = ? WHERE id = 1');
            $update->bindValue(1, $image, $image === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $update->bindValue(2, $actor, PDO::PARAM_INT);
            $update->execute();
            (new UserAccount($this->db))->audit($image === null ? 'organization_logo_removed' : 'organization_logo_updated',
                $actor, null, 'system_configuration', null);
        });
    }

    public static function version(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function decode(string|false $record): array
    {
        $values = $record === false ? [] : json_decode($record, true, 32, JSON_THROW_ON_ERROR);
        return array_replace(OperationalPolicy::DEFAULTS, array_intersect_key($values, OperationalPolicy::DEFAULTS));
    }
}
