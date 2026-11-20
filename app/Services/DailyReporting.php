<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\Database;
use Dtrms\Models\StaffReports;
use Dtrms\Models\Notifications;
use Dtrms\Models\UserAccount;
use PDO;
use PDOException;

final class DailyReporting
{
    public const RETURNED = ['returned', 'returned_by_supervisor', 'returned_by_hr'];
    public const EDITABLE = ['draft', ...self::RETURNED];
    public const TEXT_LIMIT = 4000;

    public function __construct(private PDO $db, private StaffReports $reports)
    {
    }

    public static function validDate(string $date): bool
    {
        return preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function dateLimit(?array $profile, DateTimeImmutable $today): string
    {
        return max($today->format('Y-m-d'), $profile['future_reports_through'] ?? '');
    }

    public function save(int $staff, ?int $id, array $input, bool $submit, DateTimeImmutable $today): int
    {
        try {
            return Database::transaction($this->db, function () use ($staff, $id, $input, $submit, $today): int {
                $profile = $this->reports->lockProfile($staff);
                if (!$profile || !$profile['staff_code'] || !$profile['department_active']) {
                    throw new DomainException('Ask HR to assign your staff ID and an active department before saving a report.');
                }
                $existing = $id === null ? null : $this->reports->find($staff, $id, true);
                if ($id !== null && (!$existing || !in_array($existing['status'], self::EDITABLE, true))) {
                    throw new DomainException('This report is no longer available for editing.');
                }
                if ($existing && (string) $existing['lock_version'] !== $input['lock_version']) {
                    throw new DomainException('This report changed since you opened it. Reload it before saving your changes.');
                }

                $record = $this->validate($input, $profile, $submit, $today);
                $returned = $existing && in_array($existing['status'], self::RETURNED, true);
                if ($returned && $existing['report_date'] !== $record['report_date']) {
                    throw new DomainException('Keep the original reporting date when correcting a returned report.');
                }
                if ($submit && !$this->reports->hasSupervisor($staff)) {
                    throw new DomainException('Ask HR to assign an active supervisor before submitting. You can still save a draft.');
                }

                $revision = $id === null ? 0 : $this->reports->latestRevision($id);
                if ($returned && $revision === 0) {
                    // Preserve older returned records that predate submission snapshots.
                    $this->reports->snapshot((int) $existing['id'], ++$revision, $staff, $existing, $existing['submitted_at']);
                }

                $submittedAt = $submit ? gmdate('Y-m-d H:i:s') : null;
                $id = $this->reports->writeReport($staff, $existing, $record, $submittedAt);
                $event = 'report_draft_saved';
                if ($submit) {
                    $this->reports->snapshot($id, ++$revision, $staff, $record, $submittedAt);
                    (new Notifications($this->db))->submission($staff, $id, $revision);
                    $event = $returned ? 'report_resubmitted' : 'report_submitted';
                } elseif ($returned) {
                    $event = 'report_corrections_saved';
                }
                (new UserAccount($this->db))->audit($event, $staff, $staff, 'daily_report', $id, [
                    'report_date' => $record['report_date'], 'submission_revision' => $submit ? $revision : null,
                ]);

                return $id;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('A report already exists for this reporting date. Open it from My reports.', 0, $error);
            }
            if ($error->getCode() === '45000') {
                throw new DomainException('An active supervisor assignment is required before submission.', 0, $error);
            }
            throw $error;
        }
    }

    private function validate(array $input, array $profile, bool $required, DateTimeImmutable $today): array
    {
        $date = $input['report_date'];
        if (!self::validDate($date)) {
            throw new DomainException('Enter a valid reporting date.');
        }
        if ($date > self::dateLimit($profile, $today)) {
            throw new DomainException('The reporting date exceeds your authorized date limit.');
        }

        $arrival = self::time($input['arrival_time'], 'Arrival time', $required);
        $start = self::time($input['work_start_time'], 'Time started work', $required);
        $end = self::time($input['work_end_time'], 'Time ended', $required);
        [$duration, $overnight] = self::workingDuration($arrival, $start, $end, $profile);
        $declared = $input['declared'];
        if (!in_array($declared, ['', '1'], true) || ($required && $declared !== '1')) {
            throw new DomainException('Confirm the declaration before submitting.');
        }

        return [
            'department_id' => (int) $profile['department_id'],
            'report_date' => $date,
            'content' => self::reportText($input['content'], 'Work description', $required) ?? '',
            'arrival_time' => $arrival, 'work_start_time' => $start, 'work_end_time' => $end,
            'achievement' => self::reportText($input['achievement'], 'Key achievement', $required),
            'challenges' => self::reportText($input['challenges'], 'Challenges encountered', $required),
            'declared' => (int) ($declared === '1'),
            'overnight' => (int) $overnight,
            'working_minutes' => $duration,
            'reporter_name' => $profile['name'],
            'reporter_staff_code' => $profile['staff_code'],
            'department_name_snapshot' => $profile['department'],
        ];
    }

    private static function time(string $value, string $label, bool $required): ?string
    {
        if ($value === '' && !$required) {
            return null;
        }
        if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value)) {
            throw new DomainException("Enter a valid $label in hours and minutes.");
        }
        return $value . ':00';
    }

    private static function reportText(string $value, string $label, bool $required): ?string
    {
        if ($value === '' && !$required) {
            return null;
        }
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > self::TEXT_LIMIT
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new DomainException("$label must contain 1 to " . self::TEXT_LIMIT . ' characters and no unsupported control characters.');
        }
        return $value;
    }

    private static function workingDuration(?string $arrival, ?string $start, ?string $end, array $profile): array
    {
        $arrivalMinute = $arrival !== null ? self::minutes($arrival) : null;
        $startMinute = $start !== null ? self::minutes($start) : null;
        $endMinute = $end !== null ? self::minutes($end) : null;
        if ($arrivalMinute !== null && $startMinute !== null && $startMinute < $arrivalMinute) {
            $startMinute += 1440;
        }

        $reference = $startMinute ?? $arrivalMinute;
        if ($reference !== null && $endMinute !== null) {
            if ($endMinute === $reference % 1440) {
                throw new DomainException('Time ended must be later than time started work or arrival.');
            }
            if ($endMinute < $reference) {
                $endMinute += 1440;
            }
        }
        if ($reference !== null && $endMinute !== null && $endMinute <= $reference) {
            throw new DomainException('Time ended must be later than time started work or arrival.');
        }

        $overnight = max($startMinute ?? 0, $endMinute ?? 0) >= 1440;
        if ($overnight) {
            $first = $arrivalMinute ?? $startMinute;
            $last = max($startMinute ?? 0, $endMinute ?? 0);
            if (!$profile['earliest_arrival'] || !$profile['latest_end'] || $first === null
                || $first < self::minutes($profile['earliest_arrival'])
                || $last > 1440 + self::minutes($profile['latest_end'])) {
                throw new DomainException('Check the time order. Overnight entries require a matching department work schedule.');
            }
        }

        return [$startMinute !== null && $endMinute !== null ? $endMinute - $startMinute : null, $overnight];
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
