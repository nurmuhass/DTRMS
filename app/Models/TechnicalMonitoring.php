<?php
declare(strict_types=1);

namespace Dtrms\Models;

use PDO;
use PDOException;
use PDOStatement;

final class TechnicalMonitoring
{
    public const SECTIONS = [
        'login' => 'Login activity', 'security' => 'Account security', 'sessions' => 'Active sessions',
        'audit' => 'Audit log', 'errors' => 'Application errors', 'email' => 'Email delivery',
        'jobs' => 'Scheduled tasks', 'backups' => 'Database backups', 'database' => 'Database connectivity',
    ];

    public function __construct(private PDO $db, private int $idleMinutes)
    {
    }

    public function dashboard(): array
    {
        $counts = $this->db->query("SELECT
            (SELECT COUNT(*) FROM users) AS users,
            (SELECT COUNT(*) FROM users WHERE is_active) AS active,
            (SELECT COUNT(*) FROM users WHERE NOT is_active) AS inactive,
            (SELECT COUNT(*) FROM login_attempts WHERE NOT succeeded AND attempted_at >= CURRENT_TIMESTAMP - INTERVAL 1 DAY) AS failed_logins,
            (SELECT COUNT(*) FROM email_logs WHERE status = 'failed' AND updated_at >= CURRENT_TIMESTAMP - INTERVAL 1 DAY) AS failed_email")->fetch();
        $reports = (new HrReviews($this->db))->dashboard();

        return [
            'counts' => $counts + $reports['counts'], 'departments' => $reports['departments'],
            'activity' => $this->page('audit', 1, 6), 'jobs' => $this->page('jobs', 1, 6),
        ];
    }

    public function page(string $section, int $page, int $limit = 25): array
    {
        if ($section === 'errors') {
            return $this->applicationErrors($page, $limit);
        }

        [$columns, $from, $order, $heads] = match ($section) {
            'login' => ["attempted_at, ip_address, IF(succeeded, 'Succeeded', 'Unsuccessful') AS result",
                ' FROM login_attempts', 'attempted_at DESC, id DESC', ['Attempted at', 'Source IP', 'Outcome']],
            'security' => ["u.id, r.name, IF(u.is_active, 'Active', 'Inactive'),
                IF(u.must_change_password, 'Required', 'Clear'), u.last_login_at",
                ' FROM users u JOIN roles r ON r.id = u.role_id',
                'u.must_change_password DESC, u.is_active, u.id', ['Account ID', 'Role', 'Account state', 'Password change', 'Last login']],
            'sessions' => ['u.id, r.name, s.created_at, s.last_seen_at, s.expires_at',
                ' FROM user_sessions s JOIN users u ON u.id = s.user_id JOIN roles r ON r.id = u.role_id
                WHERE s.expires_at > CURRENT_TIMESTAMP AND s.last_seen_at > TIMESTAMPADD(MINUTE, ?, CURRENT_TIMESTAMP)
                    AND u.is_active AND u.credential_version = s.credential_version',
                's.last_seen_at DESC, u.id', ['Account ID', 'Role', 'Started at', 'Last seen', 'Absolute expiry']],
            'audit' => ['created_at, event, actor_id, object_type, object_id',
                ' FROM audit_logs', 'created_at DESC, id DESC', ['Recorded at', 'Event', 'Actor ID', 'Object type', 'Object ID']],
            'email' => ['created_at, status, sent_at, updated_at',
                ' FROM email_logs', 'updated_at DESC, id DESC', ['Queued at', 'Delivery state', 'Sent at', 'Updated at']],
            'jobs' => ['job_name, scheduled_for, attempt, status, started_at, finished_at', ' FROM scheduled_job_executions',
                'started_at DESC, id DESC', ['Job', 'Scheduled for', 'Attempt', 'State', 'Started at', 'Finished at']],
            'backups' => ['scheduled_for, attempt, status, started_at, finished_at',
                " FROM scheduled_job_executions WHERE job_name = 'database_backup'",
                'started_at DESC, id DESC', ['Scheduled for', 'Attempt', 'State', 'Started at', 'Finished at']],
            default => throw new \InvalidArgumentException('Unknown monitor.'),
        };
        $parameters = $section === 'sessions' ? [-$this->idleMinutes] : [];
        $count = (int) $this->query('SELECT COUNT(*)' . $from, $parameters)->fetchColumn();
        $pages = max(1, (int) ceil($count / $limit));
        $page = min($page, $pages);
        $rows = $this->query('SELECT ' . $columns . $from . ' ORDER BY ' . $order . ' LIMIT ' . $limit
            . ' OFFSET ' . (($page - 1) * $limit), $parameters)->fetchAll(PDO::FETCH_NUM);

        foreach ($rows as &$row) {
            if ($section === 'audit') {
                $row[1] = self::identifier($row[1], 'Unclassified event');
                $row[3] = self::identifier($row[3], '—');
            } elseif ($section === 'jobs') {
                $row[0] = match ($row[0]) {
                    'database_backup' => 'Database backup',
                    'report_reminder' => 'Report reminder',
                    'notification_delivery' => 'In-app notification delivery',
                    'email_delivery' => 'Email delivery',
                    'maintenance' => 'Maintenance',
                    default => 'Other scheduled task',
                };
            } elseif ($section === 'login' && !filter_var($row[1], FILTER_VALIDATE_IP)) {
                $row[1] = 'Unknown';
            }
        }
        return compact('heads', 'rows', 'count', 'page', 'pages');
    }

    public function suspicious(): array
    {
        $window = ' WHERE NOT succeeded AND attempted_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE';
        return [
            'accounts' => (int) $this->db->query('SELECT COUNT(*) FROM (SELECT identifier_hash FROM login_attempts'
                . $window . ' GROUP BY identifier_hash HAVING COUNT(*) >= 8) flagged')->fetchColumn(),
            'sources' => $this->db->query('SELECT ip_address, COUNT(*) AS failures FROM login_attempts'
                . $window . ' GROUP BY ip_address HAVING COUNT(*) >= 30 ORDER BY failures DESC LIMIT 20')->fetchAll(),
        ];
    }

    public function connectivity(): array
    {
        $started = hrtime(true);
        try {
            $ok = (int) $this->db->query('SELECT 1')->fetchColumn() === 1;
        } catch (PDOException) {
            $ok = false;
        }
        return ['connected' => $ok, 'milliseconds' => round((hrtime(true) - $started) / 1000000, 2)];
    }

    public function backupHealth(): array
    {
        $latest = $this->db->query("SELECT status, started_at FROM scheduled_job_executions
            WHERE job_name = 'database_backup' ORDER BY started_at DESC, id DESC LIMIT 1")->fetch() ?: null;
        $succeeded = $this->db->query("SELECT MAX(finished_at) FROM scheduled_job_executions
            WHERE job_name = 'database_backup' AND status = 'succeeded'")->fetchColumn();
        return ['latest' => $latest, 'last_success' => is_string($succeeded) ? $succeeded : null];
    }

    private function applicationErrors(int $page, int $limit): array
    {
        $heads = ['Recorded at', 'Category', 'Component', 'Line'];
        $rows = [];
        $path = BASE_PATH . '/storage/logs/application.log';
        if (is_file($path) && is_readable($path) && ($file = @fopen($path, 'rb')) !== false) {
            try {
                $size = fstat($file)['size'];
                fseek($file, max(0, $size - 98304));
                $tail = stream_get_contents($file, 98304);
            } finally {
                fclose($file);
            }
            foreach (array_reverse(explode("\n", $tail ?: '')) as $line) {
                if (!preg_match('/^\[([0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9:]{8}) [A-Za-z0-9_+\/\-]+\] (.+)\s*$/D', $line, $prefix)) {
                    continue;
                }
                $record = str_starts_with($prefix[2], '{') ? json_decode($prefix[2], true) : null;
                $first = $record['exceptions'][0] ?? null;
                if (is_array($first) && is_string($first['file'] ?? null) && is_int($first['line'] ?? null)) {
                    $parts = explode('\\', (string) ($first['exception'] ?? ''));
                    $match = [null, $prefix[1], end($parts), $first['file'], $first['line']];
                } elseif (preg_match('/^([A-Za-z]+) in (.+):([0-9]+)\s*$/D', $prefix[2], $legacy)) {
                    $match = [null, $prefix[1], $legacy[1], $legacy[2], $legacy[3]];
                } else {
                    continue;
                }
                $category = match ($match[2]) {
                    'PDOException' => 'Database error',
                    'ErrorException', 'TypeError', 'ArgumentCountError', 'ParseError', 'Error' => 'PHP error',
                    'SchemaUnavailable', 'RuntimeException', 'DomainException', 'LogicException', 'InvalidArgumentException' => 'Application error',
                    default => null,
                };
                if ($category === null) {
                    continue;
                }
                $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $match[3]);
                $source = str_starts_with($candidate, BASE_PATH . DIRECTORY_SEPARATOR)
                    && !in_array('..', explode(DIRECTORY_SEPARATOR, $candidate), true) ? realpath($candidate) : false;
                $component = $source && str_starts_with($source, BASE_PATH . DIRECTORY_SEPARATOR) ? basename($source) : '—';
                $rows[] = [$match[1], $category, $component, (int) $match[4]];
                if (count($rows) >= 100) {
                    break;
                }
            }
        }
        $count = count($rows);
        $pages = max(1, (int) ceil($count / $limit));
        $page = min($page, $pages);
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);
        return compact('heads', 'rows', 'count', 'page', 'pages');
    }

    private static function identifier(?string $value, string $default): string
    {
        return $value !== null && preg_match('/\A[a-z_]{1,80}\z/', $value) ? $value : $default;
    }

    private function query(string $sql, array $parameters): PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($parameters);
        return $query;
    }
}
