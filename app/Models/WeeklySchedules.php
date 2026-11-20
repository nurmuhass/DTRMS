<?php
declare(strict_types=1);

namespace Dtrms\Models;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\Database;
use Dtrms\Services\DailyReporting;
use Dtrms\Services\WeeklyCompliance;
use PDO;

final class WeeklySchedules
{
    public const CONFIRMED = ['submitted', 'approved', 'locked'];

    public function __construct(private PDO $db)
    {
    }

    public function start(): string
    {
        return (string) $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key='flexible_schedule_start'")->fetchColumn();
    }

    public function get(int $user, string $week): ?array
    {
        $row = $this->query('SELECT * FROM staff_weekly_schedules WHERE user_id=? AND week_start=?', [$user, $week])->fetch();
        if (!$row) {
            return null;
        }
        return $row + ['days' => $this->query('SELECT scheduled_date FROM staff_weekly_schedule_days WHERE weekly_schedule_id=?
            ORDER BY scheduled_date', [$row['id']])->fetchAll(PDO::FETCH_COLUMN)];
    }

    public function dates(array $users, string $from, string $until): array
    {
        $result = [];
        foreach (array_chunk($users, 500) as $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->query("SELECT s.user_id,d.scheduled_date FROM staff_weekly_schedules s
                JOIN staff_weekly_schedule_days d ON d.weekly_schedule_id=s.id
                WHERE s.user_id IN ($marks) AND s.status IN ('submitted','approved','locked')
                AND d.scheduled_date BETWEEN ? AND ?", [...$ids,$from,$until]);
            while ($row = $rows->fetch()) {
                $result[$row['user_id']][$row['scheduled_date']] = true;
            }
        }
        return $result;
    }

    public function change(int $user, int $actor, DateTimeImmutable $week, mixed $days, string $action,
        string $reason, bool $hr, DateTimeImmutable $now): void
    {
        $from = $week->format('Y-m-d');
        $end = $week->modify('+6 days')->format('Y-m-d');
        if ($from < $this->start() || $week->format('N') !== '1'
            || (!$hr && ($user !== $actor || $week <= $now || !in_array($action, ['draft','submit'], true)))) {
            throw new DomainException('This reporting week is locked or unavailable.', 409);
        }
        if ($hr && (!in_array($action, ['correct','unlock','cancel','approve'], true)
            || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 500
            || preg_match('/[^\s\p{Z}]/u', $reason) !== 1)) {
            throw new DomainException('Enter a reason for the HR schedule action.');
        }

        Database::transaction($this->db, function () use ($user,$actor,$week,$from,$end,$days,$action,$reason,$hr,$now): void {
            $person = $this->query("SELECT u.id,u.department_id,u.is_active FROM users u JOIN roles r ON r.id=u.role_id
                WHERE u.id=? AND r.code='staff' FOR UPDATE", [$user])->fetch();
            if (!$person || (!$hr && !$person['is_active'])) {
                throw new DomainException('Staff schedule unavailable.', 404);
            }
            $old = $this->get($user,$from);
            if ((!$hr && isset($old['status']) && in_array($old['status'], ['locked','cancelled'], true))
                || (!$hr && $action==='draft' && in_array($old['status'] ?? '', self::CONFIRMED, true))
                || (in_array($action, ['unlock','approve'], true) && !in_array($old['status'] ?? '', self::CONFIRMED, true))
                || ($action === 'cancel' && (!$old || $old['status'] === 'cancelled'))) {
                throw new DomainException('The schedule is not eligible for this action.', 409);
            }
            $select = in_array($action, ['draft','submit','correct'], true);
            $picked = $select ? $this->validateDays($days,$from,$end,(int) $person['department_id'],$action !== 'draft') : $old['days'];
            if ($action !== 'draft' && $action !== 'cancel' && count($picked) !== 3) {
                throw new DomainException('A confirmed schedule requires exactly three selected dates.',409);
            }
            $affected = [];
            if ($old && ($action==='cancel' || $select)) {
                $removed = $action==='cancel' ? $old['days'] : array_values(array_diff($old['days'],$picked));
                if ($removed) {
                    $markers=implode(',',array_fill(0,count($removed),'?'));
                    $affected=$this->query("SELECT id FROM daily_reports WHERE staff_id=? AND report_date IN ($markers)
                        AND status NOT IN ('draft','cancelled')",[$user,...$removed])->fetchAll(PDO::FETCH_COLUMN);
                }
                if ($affected && !$hr) {
                    throw new DomainException('A report already exists on a removed date. Ask HR to record the correction.',409);
                }
            }
            $status = match ($action) {
                'draft' => 'draft', 'submit' => 'submitted', 'correct' => $week <= $now ? 'locked' : 'approved',
                'unlock' => 'submitted', 'cancel' => 'cancelled', 'approve' => $week <= $now ? 'locked' : 'approved',
            };
            $results = $hr ? new \Dtrms\Services\WeeklyResults($this->db) : null;
            $results?->refresh($user,$from,$end,$now,$actor,'Before HR schedule change: '.$action.'.',false);
            if (!$old) {
                $this->query('INSERT INTO staff_weekly_schedules (user_id,week_start,week_end) VALUES (?,?,?)', [$user,$from,$end]);
                $id = (int) $this->db->lastInsertId();
                $this->audit($actor,$user,$id,'created',null,['week'=>$from,'status'=>'draft','days'=>[]],$reason);
            } else {
                $id = (int) $old['id'];
            }
            if ($select) {
                $this->query('DELETE FROM staff_weekly_schedule_days WHERE weekly_schedule_id=?', [$id]);
                foreach ($picked as $date) {
                    $this->query('INSERT INTO staff_weekly_schedule_days (weekly_schedule_id,scheduled_date) VALUES (?,?)', [$id,$date]);
                }
            }
            if ($hr) {
                $this->query("UPDATE staff_weekly_schedules SET status=?, reviewed_by=?, reviewed_at=CURRENT_TIMESTAMP,
                    unlocked_at=IF(?='unlock',CURRENT_TIMESTAMP,NULL),
                    submitted_at=IF(? IN ('approved','locked'), COALESCE(submitted_at,CURRENT_TIMESTAMP),submitted_at) WHERE id=?",
                    [$status,$actor,$action,$status,$id]);
            } else {
                $this->query("UPDATE staff_weekly_schedules SET status=?,
                    submitted_at=IF(?='submitted',CURRENT_TIMESTAMP,NULL) WHERE id=?", [$status,$status,$id]);
            }
            $event = match ($action) {
                'submit'=>'submitted','correct'=>'corrected','unlock'=>'unlocked','cancel'=>'cancelled','approve'=>'approved',default=>'draft_saved',
            };
            $this->audit($actor,$user,$id,$event,$old ? ['week'=>$from,'status'=>$old['status'],'days'=>$old['days']] : null,
                ['week'=>$from,'status'=>$status,'days'=>$picked,'affected_report_ids'=>$affected],$reason);
            if ($status==='locked' && ($old['status'] ?? null)!=='locked') {
                $this->audit($actor,$user,$id,'locked',$old ? ['week'=>$from,'status'=>$old['status'],'days'=>$old['days']] : null,
                    ['week'=>$from,'status'=>$status,'days'=>$picked],$reason);
            }
            $results?->refresh($user,$from,$end,$now,$actor,$reason);
        });
    }

    public function lockStarted(DateTimeImmutable $now): int
    {
        $candidates = $this->query("SELECT id,user_id FROM staff_weekly_schedules
            WHERE status IN ('submitted','approved') AND unlocked_at IS NULL AND week_start<=? ORDER BY id",
            [$now->format('Y-m-d')])->fetchAll();
        $locked = 0;
        foreach ($candidates as $candidate) {
            $locked += Database::transaction($this->db, function () use ($candidate): int {
                $this->query('SELECT id FROM users WHERE id=? FOR UPDATE',[$candidate['user_id']])->fetchColumn();
                $row=$this->query("SELECT id,user_id,week_start,status FROM staff_weekly_schedules
                    WHERE id=? AND status IN ('submitted','approved') AND unlocked_at IS NULL FOR UPDATE",[$candidate['id']])->fetch();
                if (!$row) {
                    return 0;
                }
                $previous = $this->get((int) $row['user_id'],$row['week_start']);
                $this->query("UPDATE staff_weekly_schedules SET status='locked' WHERE id=?", [$row['id']]);
                $this->audit(null,(int) $row['user_id'],(int) $row['id'],'locked',
                    ['week'=>$row['week_start'],'status'=>$row['status'],'days'=>$previous['days']],
                    ['week'=>$row['week_start'],'status'=>'locked','days'=>$previous['days']],'Reporting week began.');
                return 1;
            });
        }
        return $locked;
    }

    public function history(int $user, int $schedule): array
    {
        return $this->query("SELECT a.event,a.created_at,a.metadata,u.name FROM weekly_schedule_audit a
            LEFT JOIN users u ON u.id=a.actor_id WHERE a.object_id=?
            AND EXISTS (SELECT 1 FROM staff_weekly_schedules s WHERE s.id=a.object_id AND s.user_id=?)
            ORDER BY a.id DESC", [$schedule,$user])->fetchAll();
    }

    public function finalHistory(int $staff,string $from,string $until): array
    {
        return $this->query('SELECT h.week_start,h.final_result,h.reason,h.created_at,u.name FROM staff_weekly_compliance_history h
            LEFT JOIN users u ON u.id=h.actor_id WHERE h.user_id=? AND h.week_start BETWEEN ? AND ?
            ORDER BY h.week_start DESC,h.id DESC',[$staff,$from,$until])->fetchAll();
    }

    private function validateDays(mixed $raw, string $from, string $end, int $department, bool $confirm): array
    {
        if (!is_array($raw) || count($raw)>3 || ($confirm && count($raw)!==3)) {
            throw new DomainException('Select exactly three distinct dates to confirm the schedule.');
        }
        $holidays = (new WorkHolidays($this->db))->dates($from,$end);
        $days = [];
        foreach ($raw as $date) {
            if (!is_string($date) || !DailyReporting::validDate($date) || $date<$from || $date>$end
                || isset($days[$date]) || isset($holidays[0][$date]) || isset($holidays[$department][$date])) {
                throw new DomainException('Dates must be distinct, eligible dates within this reporting week.');
            }
            $days[$date] = true;
        }
        ksort($days);
        return array_keys($days);
    }

    private function audit(?int $actor,int $user,int $id,string $event,?array $old,array $new,string $reason): void
    {
        (new UserAccount($this->db))->audit('weekly_schedule_'.$event,$actor,$user,'weekly_schedule',$id,
            ['previous'=>$old,'new'=>$new,'reason'=>$reason !== '' ? $reason : 'Staff schedule selection.']);
    }

    private function query(string $sql,array $args): \PDOStatement
    {
        $stmt=$this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }
}
