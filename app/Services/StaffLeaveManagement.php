<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;
use Dtrms\Core\Database;
use Dtrms\Middleware\Access;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\UserAccount;
use PDO;
use Throwable;

final class StaffLeaveManagement
{
    public const PERMISSION = 'hr_manage_organization';
    public const ROLE = 'hr_manager';

    public function __construct(private PDO $db, private StaffLeaves $leaves, private LeaveAttachments $attachments)
    {
    }

    public function save(int $id, array $input, ?array $attachment, array $actor): int
    {
        self::authorize($actor);
        $staff = self::number($input['user_id'] ?? null);
        $type = $input['leave_type'] ?? null;
        $start = $input['start_date'] ?? null;
        $end = $input['end_date'] ?? null;
        $reason = self::reason($input['reason'] ?? null, 2000, 'leave reason');
        if (!$staff || !is_string($type) || !isset(StaffLeaves::TYPES[$type])) {
            throw new DomainException('Choose a staff member and a valid leave type.');
        }
        if (!is_string($start) || !is_string($end) || !DailyReporting::validDate($start)
            || !DailyReporting::validDate($end) || $start > $end) {
            throw new DomainException('Choose valid leave dates, with the end on or after the start.');
        }

        $file = $this->attachments->store($attachment);
        try {
            [$saved, $replaced] = Database::transaction($this->db, function () use (
                $id, $staff, $type, $start, $end, $reason, $file, $actor, $input
            ): array {
                if ($id) {
                    $row = $this->lock($id, $input['version'] ?? null);
                    if ($row['status'] !== 'pending' || $staff !== (int) $row['user_id']) {
                        throw new DomainException('Only pending leave can be edited. The staff member stays the same.', 409);
                    }
                    $this->leaves->query('UPDATE staff_leaves SET leave_type = ?, start_date = ?, end_date = ?,
                        reason = ?, attachment_path = ?, lock_version = lock_version + 1 WHERE id = ?',
                        [$type, $start, $end, $reason, $file ?? $row['attachment_path'], $id]);
                    $event = 'staff_leave_modified';
                } else {
                    $exists = $this->leaves->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                        WHERE u.id = ? AND r.code = 'staff' AND u.staff_id IS NOT NULL FOR SHARE", [$staff])->fetchColumn();
                    if (!$exists) {
                        throw new DomainException('Choose a staff account with a staff ID.');
                    }
                    $this->leaves->query('INSERT INTO staff_leaves
                        (user_id, leave_type, start_date, end_date, reason, attachment_path, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$staff, $type, $start, $end, $reason, $file, $actor['id']]);
                    $id = (int) $this->db->lastInsertId();
                    $event = 'staff_leave_registered';
                }
                $this->audit($event, $actor, $staff, $id, [
                    'previous'=>isset($row) ? ['type'=>$row['leave_type'],'start'=>$row['start_date'],'end'=>$row['end_date'],
                        'reason'=>$row['reason'],'had_attachment'=>$row['attachment_path']!==null] : null,
                    'new'=>['type'=>$type,'start'=>$start,'end'=>$end,'reason'=>$reason],
                    'attachment_changed' => $file !== null,
                ]);
                return [$id, $file !== null ? ($row['attachment_path'] ?? null) : null];
            });
        } catch (Throwable $error) {
            $this->attachments->discard($file);
            throw $error;
        }
        $this->attachments->discard($replaced);
        return $saved;
    }

    public function decide(int $id, array $input, array $actor): void
    {
        self::authorize($actor);
        $decision = $input['decision'] ?? '';
        if (!in_array($decision, ['approve', 'reject', 'cancel'], true)) {
            throw new DomainException('Choose a leave decision.');
        }
        $note = $decision === 'approve' ? null : self::reason($input['decision_note'] ?? null, 500, 'decision reason');
        $override = $decision === 'approve' && ($input['allow_overlap'] ?? '') === '1'
            ? self::reason($input['overlap_reason'] ?? null, 500, 'overlap authorization reason') : null;

        Database::transaction($this->db, function () use ($id, $input, $actor, $decision, $note, $override): void {
            $row = $this->lock($id, $input['version'] ?? null);
            $status = match ($decision) {
                'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled',
            };
            if ($row['status'] !== ($decision === 'cancel' ? 'approved' : 'pending')) {
                throw new DomainException('This leave is no longer eligible for that decision.', 409);
            }
            $staff = (int) $row['user_id'];
            $now = new \DateTimeImmutable();
            $results = $status === 'rejected' ? null : new WeeklyResults($this->db);
            $results?->refresh($staff,$row['start_date'],$row['end_date'],$now,(int)$actor['id'],
                'Before leave decision (record ' . $id . ').',false);
            $overlapAllowed = null;
            if ($decision === 'approve') {
                $overlap = $this->leaves->query("SELECT id FROM staff_leaves WHERE user_id = ? AND id <> ? AND status = 'approved'
                    AND start_date <= ? AND end_date >= ? LIMIT 1 FOR UPDATE",
                    [$staff, $id, $row['end_date'], $row['start_date']])->fetchColumn();
                if ($overlap && $override === null) {
                    throw new DomainException('This leave overlaps an approved period. Explicitly authorize the overlap and give a reason to continue.', 409);
                }
                $overlapAllowed = $overlap ? $override : null;
                $this->leaves->query("UPDATE staff_leaves SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP,
                    overlap_reason = ?, lock_version = lock_version + 1 WHERE id = ?", [$actor['id'], $overlapAllowed, $id]);
            } else {
                $this->leaves->query('UPDATE staff_leaves SET status = ?, decision_note = ?,
                    lock_version = lock_version + 1 WHERE id = ?', [$status, $note, $id]);
            }
            $this->audit('staff_leave_' . $status, $actor, $staff, $id, [
                'previous'=>['status'=>$row['status'],'start'=>$row['start_date'],'end'=>$row['end_date']],
                'new'=>['status'=>$status,'start'=>$row['start_date'],'end'=>$row['end_date']],
                'reason'=>$note ?? $overlapAllowed ?? 'HR approved the registered leave.',
                'overlap_authorized'=>$overlapAllowed !== null,
            ]);
            $results?->refresh($staff,$row['start_date'],$row['end_date'],$now,
                (int)$actor['id'],'Leave ' . $status . ' (record ' . $id . ').');
        });
    }

    private function lock(int $id, mixed $version): array
    {
        $staff = $this->leaves->query('SELECT user_id FROM staff_leaves WHERE id = ?', [$id])->fetchColumn();
        if (!$staff) {
            throw new DomainException('Leave record unavailable.', 404);
        }
        $this->leaves->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$staff])->fetchColumn();
        $row = $this->leaves->query('SELECT * FROM staff_leaves WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $staff])->fetch();
        if (!$row || !is_string($version) || (string) $row['lock_version'] !== $version) {
            throw new DomainException('This leave changed. Reload the record before continuing.', 409);
        }
        return $row;
    }

    private static function authorize(array $actor): void
    {
        if (!Access::permits($actor, self::PERMISSION, self::ROLE)) {
            throw new DomainException('You cannot manage staff leave.', 403);
        }
    }

    private static function reason(mixed $value, int $limit, string $label): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
            || preg_match('/[^\p{Z}\s]/u', $value) !== 1) {
            throw new DomainException("Enter a valid $label up to $limit characters.");
        }
        return trim($value);
    }

    private static function number(mixed $value): int
    {
        return (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private function audit(string $event, array $actor, int $staff, int $id, array $details): void
    {
        (new UserAccount($this->db))->audit($event, (int) $actor['id'], $staff, 'staff_leave', $id, $details);
    }
}
