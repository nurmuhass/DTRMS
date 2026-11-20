<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DateTimeZone;
use Dtrms\Models\EmailQueue;
use Dtrms\Models\Notifications;
use Dtrms\Models\SystemConfiguration;
use PDO;
use Throwable;

final class NotificationWorker
{
    public function __construct(private PDO $db, private array $smtp)
    {
    }

    public function run(DateTimeImmutable $now, int $limit): ?array
    {
        $database = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        $key = hash('sha256', 'dtrms:notification-worker:' . $database);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$key]);
        if ((int) $lock->fetchColumn() !== 1) {
            return null;
        }

        try {
            $this->db->exec("UPDATE scheduled_job_executions SET status = 'failed', finished_at = CURRENT_TIMESTAMP,
                details = 'Recovered interrupted notification worker'
                WHERE status = 'running' AND job_name IN ('report_reminder','weekly_reporting','notification_delivery','email_delivery')");
            $config = (new SystemConfiguration($this->db))->configuration();
            $notifications = new Notifications($this->db);
            $slot = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:00');
            return [
                'weekly_reporting' => $this->job('weekly_reporting',$slot,
                    fn () => (new WeeklyReminders($this->db,$notifications))->run($now,$config)),
                'report_reminder' => $this->job('report_reminder', $slot,
                    fn () => (new ReportReminders($this->db, $notifications))->schedule($now, $config)),
                'notification_delivery' => $this->job('notification_delivery', $slot,
                    fn () => $notifications->deliverPending($limit)),
                'email_delivery' => $this->job('email_delivery', $slot, function () use ($config, $limit): array {
                    if (!$config['email_enabled']) {
                        return ['disabled' => true];
                    }
                    $delivery = new SmtpDelivery($config, $this->smtp);
                    return (new EmailQueue($this->db))->drain([$delivery, 'send'], $limit);
                }),
            ];
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$key]);
            $release->fetchColumn();
        }
    }

    private function job(string $name, string $slot, callable $work): array
    {
        $attempt = $this->db->prepare('SELECT COALESCE(MAX(attempt), 0) + 1 FROM scheduled_job_executions
            WHERE job_name = ? AND scheduled_for = ?');
        $attempt->execute([$name, $slot]);
        $insert = $this->db->prepare('INSERT INTO scheduled_job_executions (job_name, scheduled_for, attempt) VALUES (?, ?, ?)');
        $insert->execute([$name, $slot, $attempt->fetchColumn()]);
        $id = (int) $this->db->lastInsertId();
        $status = 'succeeded';
        try {
            $result = $work();
        } catch (Throwable) {
            $result = null;
            $status = 'failed';
        }
        $finish = $this->db->prepare('UPDATE scheduled_job_executions SET status = ?, finished_at = CURRENT_TIMESTAMP, details = ? WHERE id = ?');
        $finish->execute([$status, $result === null ? 'Worker operation failed' : json_encode($result, JSON_THROW_ON_ERROR), $id]);
        return compact('status', 'result');
    }
}
