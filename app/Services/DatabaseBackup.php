<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DateTimeZone;
use Dtrms\Models\SystemConfiguration;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseBackup
{
    public function __construct(
        private PDO $db,
        private array $paths,
        private string $schema,
        private string $host,
        private string $port
    )
    {
    }

    public function run(DateTimeImmutable $now, bool $manual): string
    {
        $policy = (new SystemConfiguration($this->db))->configuration();
        $scheduled = $now->setTime((int) substr($policy['backup_time'], 0, 2), (int) substr($policy['backup_time'], 3));
        if (!$manual && (!$policy['backups_enabled'] || $now < $scheduled
            || ($policy['backup_frequency'] === 'weekly' && (int) $now->format('N') !== $policy['backup_weekday']))) {
            return 'not_due';
        }

        $key = hash('sha256', 'dtrms:backup:' . $this->schema);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$key]);
        if ((int) $lock->fetchColumn() !== 1) {
            return 'busy';
        }

        $slot = ($manual ? $now : $scheduled)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        try {
            $this->db->exec("UPDATE scheduled_job_executions SET status = 'failed', finished_at = CURRENT_TIMESTAMP,
                details = 'Interrupted backup' WHERE job_name = 'database_backup' AND status = 'running'");
            $history = $this->db->prepare("SELECT COALESCE(MAX(attempt), 0), COUNT(CASE WHEN status = 'succeeded' THEN 1 END)
                FROM scheduled_job_executions WHERE job_name = 'database_backup' AND scheduled_for = ?");
            $history->execute([$slot]);
            [$attempt, $successes] = $history->fetch(PDO::FETCH_NUM);
            if (!$manual && $successes > 0) {
                return 'already_completed';
            }

            $start = $this->db->prepare("INSERT INTO scheduled_job_executions (job_name, scheduled_for, attempt)
                VALUES ('database_backup', ?, ?)");
            $start->execute([$slot, $attempt + 1]);
            $id = $this->db->lastInsertId();

            $detail = 'Backup bundle published';
            $status = 'succeeded';
            try {
                $this->publish($now);
                $this->prune($now->getTimestamp() - $policy['backup_retention_days'] * 86400);
            } catch (Throwable $error) {
                $status = 'failed';
                $detail = $error instanceof RuntimeException && !$error instanceof \PDOException
                    ? $error->getMessage() : 'Backup or retention failed';
            }

            $finish = $this->db->prepare('UPDATE scheduled_job_executions SET status = ?, finished_at = CURRENT_TIMESTAMP,
                details = ? WHERE id = ?');
            $finish->execute([$status, $detail, $id]);
            return $status;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$key]);
        }
    }

    private function privatePath(string $setting, bool $directory): string
    {
        $configured = $this->paths[$setting];
        $validPath = static fn (string $path): bool => str_starts_with($path, '/') || preg_match('~^[a-z]:[/\\\\]~i', $path);
        $path = $validPath($configured) && !is_link($configured) ? realpath($configured) : false;
        if ($path === false || ($directory ? !is_dir($path) || !is_writable($path) : !is_file($path) || !is_readable($path))) {
            throw new RuntimeException('Configure usable absolute private backup paths');
        }
        $normalize = static function (string $value): string {
            $value = rtrim(str_replace(chr(92), '/', $value), '/');
            return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
        };
        $roots = [BASE_PATH];
        $listed = array_filter(explode(PATH_SEPARATOR, $this->paths['web_roots']), 'strlen');
        if ($listed === []) {
            throw new RuntimeException('Configure BACKUP_WEB_ROOTS with every served root');
        }
        array_push($roots, ...$listed);
        for ($ancestor = dirname(BASE_PATH); $ancestor !== dirname($ancestor); $ancestor = dirname($ancestor)) {
            if (in_array(strtolower(basename($ancestor)), ['htdocs', 'wwwroot', 'public_html'], true)) {
                $roots[] = $ancestor;
            }
        }
        foreach ($roots as $root) {
            $resolved = $validPath($root) ? realpath($root) : false;
            if ($resolved === false || !is_dir($resolved) || $normalize($path) === $normalize($resolved)
                || str_starts_with($normalize($path), $normalize($resolved) . '/')) {
                throw new RuntimeException('Backup paths must be outside the application and all served roots');
            }
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Restrict backup path permissions to its owner');
        }
        return $path;
    }

    private function publish(DateTimeImmutable $now): void
    {
        $root = $this->privatePath('directory', true);
        $defaults = $this->privatePath('defaults_file', false);
        $tool = $this->paths['mysqldump'];
        if ((!str_starts_with($tool, '/') && !preg_match('~^[a-z]:[/\\\\]~i', $tool))
            || !is_file($tool) || !in_array(strtolower(basename($tool)), ['mysqldump', 'mysqldump.exe'], true)) {
            throw new RuntimeException('Configure the absolute path to MySQL mysqldump');
        }
        $name = 'dtrms-' . $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8));
        $stage = $root . '/.' . $name . '.partial';
        if (!@mkdir($stage, 0700)) {
            throw new RuntimeException('Could not create backup staging directory');
        }
        $dump = $stage . '/dump.sql';

        try {
            $command = [$tool, '--defaults-file=' . $defaults, '--host=' . $this->host, '--port=' . $this->port,
                '--protocol=TCP', '--single-transaction', '--quick', '--skip-lock-tables',
                '--skip-add-locks', '--no-tablespaces', '--set-gtid-purged=OFF', '--column-statistics=0', '--triggers',
                '--skip-add-drop-table', '--', $this->schema];
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $process = @proc_open($command, [0 => ['file', $null, 'rb'], 1 => ['file', $dump, 'wb'],
                2 => ['file', $null, 'wb']], $pipes, options: ['bypass_shell' => true]);
            if (!is_resource($process) || proc_close($process) !== 0 || !is_file($dump) || !filesize($dump)) {
                throw new RuntimeException('MySQL dump failed or returned no data');
            }
            $hash = hash_file('sha256', $dump);
            $manifest = json_encode(['schema' => $this->schema, 'timestamp' => $now->getTimestamp(),
                'bytes' => filesize($dump), 'sha256' => $hash], JSON_THROW_ON_ERROR);
            if ($hash === false || @file_put_contents($stage . '/manifest.json', $manifest) !== strlen($manifest)
                || !@rename($stage, $root . '/' . $name)) {
                throw new RuntimeException('Could not verify and publish the backup bundle');
            }
        } finally {
            if (is_dir($stage)) {
                @unlink($dump);
                @unlink($stage . '/manifest.json');
                @rmdir($stage);
            }
        }
    }

    private function prune(int $cutoff): void
    {
        $root = $this->privatePath('directory', true);
        foreach (new \FilesystemIterator($root) as $entry) {
            if (!$entry->isDir() || $entry->isLink() || !preg_match('/\Adtrms-\d{8}T\d{6}Z-[a-f0-9]{16}\z/', $entry->getFilename())) {
                continue;
            }
            $folder = $entry->getPathname();
            $manifestPath = $folder . '/manifest.json';
            $dump = $folder . '/dump.sql';
            if (is_link($manifestPath) || is_link($dump) || !is_file($manifestPath) || !is_file($dump)
                || count(iterator_to_array(new \FilesystemIterator($folder))) !== 2) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($manifestPath, length: 4096), true);
            if (($manifest['schema'] ?? null) === $this->schema && is_int($manifest['timestamp'] ?? null)
                && substr($entry->getFilename(), 6, 16) === gmdate('Ymd\THis\Z', $manifest['timestamp'])
                && is_int($manifest['bytes'] ?? null) && $manifest['bytes'] === filesize($dump)
                && is_string($manifest['sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $manifest['sha256'])
                && $manifest['timestamp'] < $cutoff
                && (!$this->removeExpired($folder, $manifestPath, $dump))) {
                throw new RuntimeException('Backup published, but retention cleanup failed');
            }
        }
    }

    private function removeExpired(string $folder, string $manifest, string $dump): bool
    {
        return @unlink($dump) && @unlink($manifest) && @rmdir($folder);
    }
}
