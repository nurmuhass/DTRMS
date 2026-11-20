<?php
declare(strict_types=1);

namespace Dtrms\Models;

use Dtrms\Core\Database;
use Dtrms\Services\NotificationText;
use LogicException;
use PDO;

final class Notifications
{
    private ?bool $emailEnabled = null;

    public function __construct(private PDO $db)
    {
    }

    public function submission(int $staff, int $report, int $revision): void
    {
        $this->publish($staff, 'report_submitted', $report, (string) $revision);
        $query = $this->db->prepare("SELECT a.id, a.supervisor_id FROM supervisor_assignments a
            JOIN users u ON u.id = a.supervisor_id AND u.is_active JOIN roles r ON r.id = u.role_id AND r.code = 'supervisor'
            WHERE a.current_staff_id = ?");
        $query->execute([$staff]);
        $assignment = $query->fetch();
        if ($assignment) {
            $this->publish((int) $assignment['supervisor_id'], 'report_assigned', $report, $revision . ':' . $assignment['id']);
        }
    }

    public function reassignment(int $staff, int $supervisor, int $assignment): void
    {
        $this->publish($staff, 'supervisor_changed', null, (string) $assignment);
        $query = $this->db->prepare("SELECT r.id, MAX(v.revision_number) AS revision FROM daily_reports r
            JOIN report_revisions v ON v.report_id = r.id WHERE r.staff_id = ? AND r.status = 'submitted' GROUP BY r.id");
        $query->execute([$staff]);
        foreach ($query->fetchAll() as $report) {
            $this->publish($supervisor, 'report_assigned', (int) $report['id'], $report['revision'] . ':' . $assignment);
        }
    }

    public function supervisorDecision(int $staff, int $report, int $revision, bool $approved): void
    {
        $this->publish($staff, $approved ? 'report_supervisor_approved' : 'report_returned_by_supervisor', $report, (string) $revision);
        if (!$approved) {
            return;
        }
        $query = $this->db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
            JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
            WHERE u.is_active AND r.code IN ('hr_officer','hr_manager') AND p.code = ?");
        $query->execute([HrReviews::READ]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $user) {
            $this->publish((int) $user, 'report_pending_hr', $report, (string) $revision);
        }
    }

    public function publish(int $user, string $type, ?int $report, string $period): bool
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Notification publishing requires a transaction.');
        }
        $key = hash('sha256', implode(':', [$user, $type, $report ?? 0, $period]));
        $insert = $this->db->prepare('INSERT INTO notification_events (event_key, user_id, report_id, type, message)
            VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = notification_events.id');
        $insert->execute([$key, $user, $report, $type, NotificationText::message($type)]);
        if ($insert->rowCount() === 0) {
            return false;
        }

        $event = (int) $this->db->lastInsertId();
        $this->deliver($event);
        if (NotificationText::email($type) && ($this->emailEnabled ??= (new SystemConfiguration($this->db))->configuration()['email_enabled'])) {
            $email = $this->db->prepare("INSERT INTO email_queue (notification_event_id, recipient_hash)
                SELECT ?, SHA2(LOWER(TRIM(email)), 256) FROM users WHERE id = ? AND is_active");
            $email->execute([$event, $user]);
        }
        return true;
    }

    public function deliverPending(int $limit): int
    {
        return Database::transaction($this->db, function () use ($limit): int {
            $query = $this->db->query('SELECT id FROM notification_events WHERE delivered_at IS NULL ORDER BY id LIMIT '
                . max(1, min(100, $limit)) . ' FOR UPDATE');
            $pending = $query->fetchAll(PDO::FETCH_COLUMN);
            foreach ($pending as $id) {
                $this->deliver((int) $id);
            }
            return count($pending);
        });
    }

    private function deliver(int $event): void
    {
        $delivery = $this->db->prepare('INSERT INTO notifications (notification_event_id, user_id, report_id, type, message)
            SELECT id, user_id, report_id, type, message FROM notification_events WHERE id = ? AND delivered_at IS NULL
            ON DUPLICATE KEY UPDATE notification_event_id = notifications.notification_event_id');
        $delivery->execute([$event]);
        $update = $this->db->prepare('UPDATE notification_events SET delivered_at = CURRENT_TIMESTAMP WHERE id = ? AND delivered_at IS NULL');
        $update->execute([$event]);
    }

    public function inbox(int $user, int $page): array
    {
        $count = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $count->execute([$user]);
        $count = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($count / 20));
        $page = min($page, $pages);
        return ['rows' => $this->recent($user, 20, ($page - 1) * 20), 'count' => $count, 'page' => $page, 'pages' => $pages];
    }

    public function recent(int $user, int $limit = 6, int $offset = 0): array
    {
        $query = $this->db->prepare('SELECT n.id, n.report_id, n.type, n.message, n.read_at, n.created_at,
            r.reference_number, r.staff_id, a.supervisor_id FROM notifications n LEFT JOIN daily_reports r ON r.id = n.report_id
            LEFT JOIN supervisor_assignments a ON a.current_staff_id = r.staff_id
            WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT ' . min(20, max(1, $limit)) . ' OFFSET ' . max(0, $offset));
        $query->execute([$user]);
        return $query->fetchAll();
    }

    public function unread(int $user): int
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $query->execute([$user]);
        return (int) $query->fetchColumn();
    }

    public function read(int $user, int $id): bool
    {
        $query = $this->db->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP
            WHERE user_id = ? AND id = ? AND read_at IS NULL');
        $query->execute([$user, $id]);
        if ($query->rowCount() > 0) {
            return true;
        }
        $exists = $this->db->prepare('SELECT 1 FROM notifications WHERE user_id = ? AND id = ?');
        $exists->execute([$user, $id]);
        return (bool) $exists->fetchColumn();
    }

    public function readAll(int $user): void
    {
        $query = $this->db->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL');
        $query->execute([$user]);
    }
}
