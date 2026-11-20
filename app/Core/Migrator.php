<?php
declare(strict_types=1);

namespace Dtrms\Core;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(private PDO $database, private string $directory)
    {
    }

    public function migrate(): array
    {
        $schema = (string) $this->database->query('SELECT DATABASE()')->fetchColumn();
        $lock = hash('sha256', 'dtrms:migrations:' . $schema);
        $acquire = $this->database->prepare('SELECT GET_LOCK(?, 10)');
        $acquire->execute([$lock]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the migration lock.');
        }

        try {
            $this->database->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(128) PRIMARY KEY,
                    checksum CHAR(64) NOT NULL,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB'
            );

            $applied = $this->database->query(
                'SELECT version, checksum FROM schema_migrations'
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $files = glob($this->directory . '/*.sql');
            if ($files === false || !is_dir($this->directory)) {
                throw new RuntimeException('Could not list migration files.');
            }
            sort($files, SORT_STRING);

            $pending = [];
            $seen = [];
            foreach ($files as $file) {
                $version = basename($file, '.sql');
                $sql = file_get_contents($file);
                if (
                    strlen($version) > 128 || !preg_match('/\A[0-9]{14}_[a-z0-9_]+\z/', $version)
                    || $sql === false || trim($sql) === ''
                ) {
                    throw new RuntimeException('Invalid migration file.');
                }

                $checksum = hash('sha256', $sql);
                $seen[$version] = true;
                if (isset($applied[$version])) {
                    if ($applied[$version] !== $checksum) {
                        throw new RuntimeException('An applied migration has changed.');
                    }
                    continue;
                }
                if ($applied !== [] && strcmp($version, max(array_keys($applied))) < 0) {
                    throw new RuntimeException('A pending migration sorts before applied migrations.');
                }
                $pending[$version] = [$sql, $checksum];
            }

            if (array_diff_key($applied, $seen) !== []) {
                throw new RuntimeException('An applied migration file is missing.');
            }

            $record = $this->database->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
            foreach ($pending as $version => [$sql, $checksum]) {
                $this->database->exec($sql);
                $record->execute([$version, $checksum]);
            }

            return array_keys($pending);
        } finally {
            $release = $this->database->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }
}
