<?php
declare(strict_types=1);

namespace Dtrms\Services;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class SmtpDelivery
{
    private string $baseUrl;

    public function __construct(private array $settings, private array $secrets)
    {
        $url = rtrim($secrets['base_url'], '/');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['path']) || !filter_var($url, FILTER_VALIDATE_URL)
            || $settings['smtp_host'] === '' || !filter_var($settings['email_from'], FILTER_VALIDATE_EMAIL)
            || (($secrets['username'] === '') !== ($secrets['password'] === ''))) {
            throw new RuntimeException('SMTP worker configuration is incomplete.');
        }
        if ($secrets['ca_file'] !== '' && !is_readable($secrets['ca_file'])) {
            throw new RuntimeException('SMTP trust configuration is unavailable.');
        }
        if ($secrets['username'] !== '' && strcasecmp($settings['smtp_host'], $secrets['relay_host'] ?? '') !== 0) {
            throw new RuntimeException('Authenticated SMTP requires the deployment-approved relay host.');
        }
        $this->baseUrl = $url;
    }

    public function send(array $event): string
    {
        $smtp = new TrackedSmtp();
        $smtp->Timelimit = 20;
        $mail = new PHPMailer(true);
        $mail->setSMTPInstance($smtp);
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Timeout = 12;
        $mail->Host = $this->settings['smtp_host'];
        $mail->Port = $this->settings['smtp_port'];
        $mail->SMTPSecure = $this->settings['smtp_encryption'] === 'tls' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = $this->secrets['username'] !== '';
        $mail->Username = $this->secrets['username'];
        $mail->Password = $this->secrets['password'];
        if ($this->secrets['ca_file'] !== '') {
            $mail->SMTPOptions = ['ssl' => ['cafile' => $this->secrets['ca_file'], 'verify_peer' => true, 'verify_peer_name' => true]];
        }
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->MessageID = '<dtrms-' . $event['event_key'] . '-' . substr(hash('sha256', $this->baseUrl), 0, 12) . '@dtrms.internal>';
        $mail->Subject = NotificationText::subject($event['type']);

        $url = $this->baseUrl . '/notifications';
        $app = $this->settings['organization_name'] ?: $this->settings['app_name'];
        $mail->isHTML(true);
        $mail->Body = $this->html($app, $mail->Subject, $event['message'], $url);
        $mail->AltBody = $mail->Subject . "\n\n" . $event['message'] . "\n\nOpen your portal: " . $url;
        try {
            $mail->setFrom($this->settings['email_from'], $this->settings['email_from_name'] ?: $app);
            $mail->addAddress($event['email']);
            return $mail->send() ? 'sent' : $smtp->failure();
        } catch (Exception) {
            // Never expose SMTP replies or PHPMailer diagnostics to logs or monitoring.
            return $smtp->failure();
        }
    }

    private function html(string $organization, string $title, string $message, string $url): string
    {
        $organization = e($organization);
        $title = e($title);
        $message = e($message);
        $url = e($url);
        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>$title</title></head>
<body style="margin:0;background:#f5f7fa;color:#12203a;font:16px/1.6 Arial,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="18"><tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="24" style="max-width:560px;background:white">
            <tr><td style="background:#091530;color:white;border-bottom:4px solid #c9a84c;font-weight:bold">$organization</td></tr>
            <tr><td>
                <h1 style="margin:0;font-size:24px">$title</h1>
                <p>$message</p>
                <p><a href="$url" style="display:inline-block;background:#0f2044;color:white;padding:10px 18px;text-decoration:none">Open notifications</a></p>
            </td></tr>
        </table>
    </td></tr></table>
</body></html>
HTML;
    }
}
