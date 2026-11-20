<?php
declare(strict_types=1);

namespace Dtrms\Models;

use Dtrms\Core\Database;
use PDO;

final class EmailQueue
{
    private const RETRY_MINUTES = [1 => 5, 2 => 15, 3 => 60];

    public function __construct(private PDO $db)
    {
    }

    public function drain(callable $send, int $limit): array
    {
        $counts = ['sent' => 0, 'retry' => 0, 'failed' => $this->recoverInterrupted(), 'skipped' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $event = $this->claim();
            if (!$event) {
                break;
            }
            $email = strtolower(trim($event['email']));
            if (!$event['is_active'] || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !hash_equals($event['recipient_hash'], hash('sha256', $email))) {
                $counts[$this->finish($event, 'recipient_unavailable')]++;
                continue;
            }
            $counts[$this->finish($event, $send($event))]++;
        }
        return $counts;
    }

    // The scheduler holds a schema-level worker lock for this entire operation.
    private function claim(): ?array
    {
        return Database::transaction($this->db, function (): ?array {
            $event = $this->db->query("SELECT q.id, q.attempts, q.recipient_hash, e.event_key, e.type, e.message,
                u.id AS user_id, u.email, u.is_active
                FROM email_queue q JOIN notification_events e ON e.id = q.notification_event_id JOIN users u ON u.id = e.user_id
                WHERE q.status = 'pending' AND q.available_at <= CURRENT_TIMESTAMP ORDER BY q.available_at, q.id LIMIT 1 FOR UPDATE")->fetch();
            if (!$event) {
                return null;
            }
            $update = $this->db->prepare("UPDATE email_queue SET status = 'processing', attempts = attempts + 1,
                started_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$event['id']]);
            $event['attempts']++;
            return $event;
        });
    }

    private function recoverInterrupted(): int
    {
        $query = $this->db->query("SELECT q.id, q.attempts, u.id AS user_id, u.email, e.type
            FROM email_queue q JOIN notification_events e ON e.id = q.notification_event_id JOIN users u ON u.id = e.user_id
            WHERE q.status = 'processing'");
        $events = $query->fetchAll();
        foreach ($events as $event) {
            $this->finish($event, 'delivery_uncertain');
        }
        return count($events);
    }

    private function finish(array $event, string $result): string
    {
        return Database::transaction($this->db, function () use ($event, $result): string {
            $delay = $result === 'smtp_retryable' ? (self::RETRY_MINUTES[$event['attempts']] ?? 0) : 0;
            $status = match (true) {
                $result === 'sent' => 'sent',
                $result === 'recipient_unavailable' => 'skipped',
                $delay > 0 => 'pending',
                default => 'failed',
            };
            $update = $this->db->prepare("UPDATE email_queue SET status = ?, last_result = ?,
                available_at = TIMESTAMPADD(MINUTE, ?, CURRENT_TIMESTAMP) WHERE id = ? AND status = 'processing' AND attempts = ?");
            $update->execute([$status, $result, $delay, $event['id'], $event['attempts']]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Email processing state changed.');
            }
            if ($status !== 'skipped') {
                $log = $this->db->prepare("INSERT INTO email_logs (user_id, recipient, template, status, sent_at, queue_id, attempt)
                    VALUES (?, ?, ?, ?, IF(? = 'sent', CURRENT_TIMESTAMP, NULL), ?, ?)");
                $resultStatus = $status === 'sent' ? 'sent' : 'failed';
                $recipient = $result === 'delivery_uncertain' ? 'Unavailable' : $event['email'];
                $log->execute([$event['user_id'], $recipient, $event['type'], $resultStatus, $resultStatus, $event['id'], $event['attempts']]);
            }
            return $status === 'pending' ? 'retry' : $status;
        });
    }
}
