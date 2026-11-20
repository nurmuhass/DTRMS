<?php
declare(strict_types=1);

namespace Dtrms\Services;

use PHPMailer\PHPMailer\SMTP;

final class TrackedSmtp extends SMTP
{
    private bool $dataAttempted = false;
    private bool $accepted = false;
    private int $dataReply = 0;

    public function data($msg_data)
    {
        $this->dataAttempted = true;
        $this->accepted = parent::data($msg_data);
        $this->dataReply = (int) ($this->getError()['smtp_code'] ?? 0);
        return $this->accepted;
    }

    public function failure(): string
    {
        if ($this->accepted) {
            return 'sent';
        }
        $reply = $this->dataAttempted ? $this->dataReply : (int) ($this->getError()['smtp_code'] ?? 0);
        if ($reply >= 500 && $reply < 600) {
            return 'smtp_rejected';
        }
        if (!$this->dataAttempted || ($reply >= 400 && $reply < 500)) {
            return 'smtp_retryable';
        }
        return 'delivery_uncertain';
    }
}
