<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;

final class OperationalPolicy
{
    public const DAYS = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public const DEFAULTS = [
        'app_name' => 'Daily Task Reporting and Management System', 'organization_name' => '',
        'working_days' => [1, 2, 3, 4, 5], 'work_start' => '08:00', 'work_end' => '17:00',
        'arrival_grace' => 15, 'report_deadline' => '18:00', 'reminders_enabled' => false, 'reminder_time' => '16:30',
        'reminder_interval_minutes' => 60,
        'email_enabled' => false, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'starttls',
        'email_from' => '', 'email_from_name' => '', 'session_idle_minutes' => 30,
        'backups_enabled' => false, 'backup_frequency' => 'daily', 'backup_weekday' => 7,
        'backup_time' => '02:00', 'backup_retention_days' => 30,
    ];

    public static function validate(array $input): array
    {
        $values = [];
        foreach (['app_name' => 'Application name', 'organization_name' => 'Organization name',
            'smtp_host' => 'SMTP host', 'email_from' => 'Sender address', 'email_from_name' => 'Sender name'] as $key => $label) {
            $value = $input[$key] ?? '';
            $limit = in_array($key, ['smtp_host', 'email_from'], true) ? 254 : 120;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
                || preg_match('/[\x00-\x1f\x7f]/', $value) || ($key === 'app_name' && trim($value) === '')) {
                throw new DomainException("Enter a valid $label.");
            }
            $values[$key] = trim($value);
        }
        foreach (['work_start', 'work_end', 'report_deadline', 'reminder_time', 'backup_time'] as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value)) {
                throw new DomainException('Enter valid times for working hours, reporting, reminders, and backups.');
            }
            $values[$field] = $value;
        }
        if ($values['work_start'] >= $values['work_end']) {
            throw new DomainException('Official working hours must start before they end on the same day.');
        }
        $days = $input['working_days'] ?? [];
        if (!is_array($days) || $days === [] || count($days) > 7) {
            throw new DomainException('Choose at least one valid working day.');
        }
        $values['working_days'] = [];
        foreach ($days as $day) {
            if (!is_string($day) || !preg_match('/\A[1-7]\z/', $day)) {
                throw new DomainException('Choose valid working days.');
            }
            $values['working_days'][] = (int) $day;
        }
        $values['working_days'] = array_values(array_unique($values['working_days']));
        sort($values['working_days']);
        foreach ([
            'arrival_grace' => [0, 120, 'Arrival grace'], 'smtp_port' => [1, 65535, 'SMTP port'],
            'session_idle_minutes' => [5, 120, 'Session inactivity timeout'],
            'reminder_interval_minutes' => [15, 480, 'Review reminder interval'],
            'backup_weekday' => [1, 7, 'Backup weekday'], 'backup_retention_days' => [1, 365, 'Backup retention'],
        ] as $key => [$min, $max, $label]) {
            $number = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($number === false) {
                throw new DomainException("$label must be between $min and $max.");
            }
            $values[$key] = $number;
        }
        foreach (['email_enabled', 'reminders_enabled', 'backups_enabled'] as $field) {
            $value = $input[$field] ?? '0';
            if (!in_array($value, ['0', '1'], true)) {
                throw new DomainException('Choose a valid enable or disable option.');
            }
            $values[$field] = $value === '1';
        }
        foreach (['smtp_encryption' => ['starttls', 'tls'], 'backup_frequency' => ['daily', 'weekly']] as $field => $options) {
            $value = $input[$field] ?? '';
            if (!in_array($value, $options, true)) {
                throw new DomainException('Choose a valid encryption and backup schedule.');
            }
            $values[$field] = $value;
        }
        if (($values['smtp_host'] !== '' && !filter_var($values['smtp_host'], FILTER_VALIDATE_IP)
                && !filter_var($values['smtp_host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            || ($values['email_from'] !== '' && !filter_var($values['email_from'], FILTER_VALIDATE_EMAIL))
            || ($values['email_enabled'] && ($values['smtp_host'] === '' || $values['email_from'] === ''))) {
            throw new DomainException('Enter a valid SMTP host and sender address to enable email.');
        }
        return array_replace(self::DEFAULTS, $values);
    }
}
