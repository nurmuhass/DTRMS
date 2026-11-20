<?php
declare(strict_types=1);

// Loaded by authentication.php against its disposable database.
use Dtrms\Core\Database;
use Dtrms\Models\EmailQueue;
use Dtrms\Models\Notifications;
use Dtrms\Services\NotificationWorker;
use Dtrms\Services\ReportReminders;
use Dtrms\Services\SmtpDelivery;

$loginWith($portal, 'portal-1@example.test', $boundaryPassword, 303, '/');
$feed = $portal->request('/notifications/feed', 200, json: true);
$state = json_decode($feed->body(), true, 32, JSON_THROW_ON_ERROR);
expect($state['unread'] > 0 && $state['items'] !== [], 'recipient feed and unread count');
expect($portal->request('/staff', 200)->contains('data-notifications'), 'notification bell appears on portal pages');
$types = $db->query('SELECT DISTINCT type FROM notification_events')->fetchAll(PDO::FETCH_COLUMN);
foreach (['report_submitted','report_assigned','report_supervisor_approved','report_pending_hr','report_returned_by_supervisor',
    'report_hr_approved','report_returned_by_hr','account_created','password_reset'] as $eventType) {
    expect(in_array($eventType, $types, true), "$eventType published by the workflow");
}
$otherUnread = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE user_id = $otherId AND read_at IS NULL")->fetchColumn();
$portal->request('/notifications/read-all', 403, [], json: true);
$portalToken = $portal->request('/notifications', 200)->csrf();
$markAll = $portal->request('/notifications/read-all', 200, ['csrf' => $portalToken], json: true);
expect(json_decode($markAll->body(), true)['unread'] === 0, 'AJAX mark all updates count');
expect((int) $db->query("SELECT COUNT(*) FROM notifications WHERE user_id = $otherId AND read_at IS NULL")->fetchColumn() === $otherUnread,
    'mark all is recipient-scoped');
$anonymous->request('/notifications/feed', 303, location: '/login');
expect($anonymous->request('/notifications/feed', 401, json: true)->contains('authentication_required'), 'AJAX session expiry returns JSON and HTTP 401');
expect($portal->request('/notifications/read', 422, ['csrf' => $portalToken], json: true)->contains('invalid_notification'),
    'malformed notification ID returns JSON and HTTP 422');
expect($portal->request('/notifications/read', 404, ['csrf' => $portalToken, 'id' => PHP_INT_MAX], json: true)->contains('not_found'),
    'unknown notification returns JSON and HTTP 404');

$pendingDate = $today->modify('-4 days')->format('Y-m-d');
$portal->request($editor, 303, ['csrf' => $portalToken] + array_replace($complete, ['report_date' => $pendingDate]));
$pendingReport = (int) $db->query("SELECT id FROM daily_reports WHERE staff_id = $owner AND report_date = '$pendingDate'")->fetchColumn();
$supervisorFeed = $anotherReviewer->request('/notifications/feed', 200, json: true);
expect(str_contains($supervisorFeed->body(), 'supervisor review') && in_array('/supervisor/report?id=' . $pendingReport,
    array_column(json_decode($supervisorFeed->body(), true)['items'], 'url'), true), 'supervisor feed links to its assigned report');

$publisher = new Notifications($db);
$settings = $storedSettings->configuration();
$beforeMail = array_replace($settings, ['reminders_enabled' => false]);
$relaySettings = array_replace($beforeMail, ['smtp_host' => 'approved.example.test', 'email_from' => 'mail@example.test']);
$relaySecrets = ['base_url' => 'https://dtrms.example.test', 'username' => 'qa', 'password' => 'fixture',
    'ca_file' => '', 'relay_host' => 'approved.example.test'];
new SmtpDelivery($relaySettings, $relaySecrets);
try {
    new SmtpDelivery(array_replace($relaySettings, ['smtp_host' => 'other.example.test']), $relaySecrets);
    expect(false, 'authenticated SMTP refuses a changed relay host');
} catch (RuntimeException $error) {
    expect(str_contains($error->getMessage(), 'deployment-approved relay'), 'authenticated relay pin checked');
}
$storedSettings->update($adminId, $beforeMail, $storedSettings::version($settings));
// Isolate the SMTP fixture from email produced by earlier regression cases.
$db->exec("UPDATE email_queue SET status = 'skipped' WHERE status = 'pending'");
$mailUsers = [];
foreach (['accept','retry','reject','uncertain','change'] as $name) {
    $fixture->execute([$deptId, 'EMAIL-' . strtoupper($name), 'Email QA', $name . '@example.test', $hash]);
    $mailUsers[$name] = (int) $db->lastInsertId();
}
Database::transaction($db, static function () use ($publisher, $mailUsers): void {
    foreach ($mailUsers as $name => $user) {
        $type = $name === 'accept' ? 'password_reset' : 'account_created';
        expect($publisher->publish($user, $type, null, 'mail-test'), 'new email event created');
        expect(!$publisher->publish($user, $type, null, 'mail-test'), 'duplicate event is idempotent');
    }
});
$db->exec("UPDATE users SET email = 'changed@example.test' WHERE id = " . $mailUsers['change']);
$smtpPort = getenv('DTRMS_TEST_SMTP_PORT');
$deliveryQueue = new EmailQueue($db);
$workerAt = new DateTimeImmutable('2026-11-16 12:30:00');
if ($smtpPort !== false && $smtpPort !== '') {
    $smtpPolicy = array_replace($beforeMail, ['smtp_host' => '127.0.0.1', 'smtp_port' => (int) $smtpPort, 'smtp_encryption' => 'tls']);
    $storedSettings->update($adminId, $smtpPolicy, $storedSettings::version($beforeMail));
    $mailWorker = new NotificationWorker($db, [
        'base_url' => 'https://dtrms.example.test', 'username' => '', 'password' => '',
        'ca_file' => getenv('DTRMS_TEST_SMTP_CA_FILE') ?: '',
    ]);
    $runMail = static function () use ($mailWorker, $workerAt): array {
        $run = $mailWorker->run($workerAt, 20);
        expect(($run['email_delivery']['status'] ?? null) === 'succeeded', 'SMTP worker ran');
        return $run['email_delivery']['result'];
    };
} else {
    $send = static fn (array $event): string => match ($event['email']) {
        'retry@example.test' => $event['attempts'] === 1 ? 'smtp_retryable' : 'sent',
        'reject@example.test' => 'smtp_rejected',
        'uncertain@example.test' => 'delivery_uncertain',
        default => 'sent',
    };
    $runMail = static fn (): array => $deliveryQueue->drain($send, 20);
}
expect($runMail() === ['sent' => 1, 'retry' => 1, 'failed' => 2, 'skipped' => 1], 'send, retryable, permanent, uncertain and replaced recipient outcomes');
expect($runMail() === ['sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0], 'retry waits until due');
$db->exec("UPDATE email_queue SET available_at = CURRENT_TIMESTAMP WHERE status = 'pending'");
expect($runMail() === ['sent' => 1, 'retry' => 0, 'failed' => 0, 'skipped' => 0], 'retry delivers once');
$queueFor = $db->prepare('SELECT q.id, q.status, q.attempts, q.last_result FROM email_queue q
    JOIN notification_events e ON e.id = q.notification_event_id WHERE e.user_id = ?');
$queueFor->execute([$mailUsers['retry']]);
$retryRow = $queueFor->fetch();
expect($retryRow['status'] === 'sent' && (int) $retryRow['attempts'] === 2
    && (int) $db->query('SELECT COUNT(*) FROM email_logs WHERE queue_id = ' . (int) $retryRow['id'])->fetchColumn() === 2,
    'each delivery attempt logged once');
Database::transaction($db, static fn () => $publisher->publish($mailUsers['accept'], 'password_reset_completed', null, 'interrupted'));
$db->exec("UPDATE email_queue SET status = 'processing', attempts = 1 WHERE status = 'pending'");
expect($runMail() === ['sent' => 0, 'retry' => 0, 'failed' => 1, 'skipped' => 0], 'interrupted delivery is flagged without resending');
if ($smtpPort !== false && $smtpPort !== '') {
    $journalPath = getenv('DTRMS_TEST_SMTP_JOURNAL');
    $journal = array_map(static fn (string $line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR),
        file($journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    expect(count($journal) === 5, 'relay accepted exactly five SMTP attempts');
    expect(str_contains($journal[0]['message'], 'text/html') && str_contains($journal[0]['message'], 'Open notifications')
        && str_contains($journal[0]['message'], 'https://dtrms.example.test/notifications'), 'PHPMailer sent secure portal-linked HTML');
    $retries = array_values(array_filter($journal, static fn (array $entry): bool => $entry['recipient'] === 'retry@example.test'));
    preg_match('/^Message-ID: (.+)$/m', $retries[0]['message'], $firstMessage);
    preg_match('/^Message-ID: (.+)$/m', $retries[1]['message'], $againMessage);
    expect(isset($firstMessage[1], $againMessage[1]) && $firstMessage[1] === $againMessage[1], 'retries preserve Message-ID');
    echo "PASS: real PHPMailer SMTP over TLS with a local relay, including retry and uncertain delivery\n";
} else {
    echo "PASS: email queue exercised with deterministic transport outcomes\n";
}

$currentOperational = $storedSettings->configuration();
$reminderPolicy = array_replace($settings, ['email_enabled' => false, 'reminder_interval_minutes' => 60]);
$storedSettings->update($adminId, $reminderPolicy, $storedSettings::version($currentOperational));
$reminders = new ReportReminders($db, new Notifications($db));
$missingCount = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM notification_events WHERE type = 'reminder_missing_report'")->fetchColumn();
expect($reminders->schedule(new DateTimeImmutable('2026-11-17 19:00:00'), $reminderPolicy) === 0, 'no reminders on non-working days');
expect($reminders->schedule(new DateTimeImmutable('2026-11-16 15:00:00'), $reminderPolicy) === 0, 'configured reminder start respected');
expect($reminders->schedule(new DateTimeImmutable('2026-11-16 17:30:00'), $reminderPolicy) > 0 && $missingCount() === 0,
    'review reminders start before missing-report deadline');
expect($reminders->schedule(new DateTimeImmutable('2026-11-16 17:35:00'), $reminderPolicy) === 0, 'review reminders deduplicate per interval');
expect($reminders->schedule(new DateTimeImmutable('2026-11-16 18:00:00'), $reminderPolicy) > 0 && $missingCount() > 0, 'missing report reminders after deadline');
$dailyTotal = $missingCount();
expect($reminders->schedule(new DateTimeImmutable('2026-11-16 19:00:00'), $reminderPolicy) > 0
    && $missingCount() === $dailyTotal, 'review reminders recur but missing report remains once per day');

$cli = proc_open([PHP_BINARY, __DIR__ . '/../bin/notifications-worker.php', '10'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
expect(is_resource($cli), 'scheduled CLI starts');
$workerOutput = stream_get_contents($pipe[1]);
$workerErrors = stream_get_contents($pipe[2]);
fclose($pipe[1]);
fclose($pipe[2]);
expect(proc_close($cli) === 0 && $workerErrors === '' && isset(json_decode($workerOutput, true)['notification_delivery']), 'scheduled CLI runs without browser');
expect((int) $db->query("SELECT COUNT(*) FROM scheduled_job_executions WHERE job_name = 'notification_delivery' AND status = 'succeeded'")->fetchColumn() > 0, 'scheduled run logged');

echo "PASS: event coverage, role-scoped links, AJAX inbox, deduplication, reminders and scheduler logging\n";
