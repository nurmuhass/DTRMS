<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use Dtrms\Core\Database;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Models\SystemConfiguration;
use Dtrms\Models\WeeklySchedules;
use PDO;

final class WeeklyResults
{
    public function __construct(private PDO $db)
    {
    }

    public function finalize(DateTimeImmutable $now): int
    {
        $start = (new WeeklySchedules($this->db))->start();
        $total = 0;
        for ($week = new DateTimeImmutable($start,$now->getTimezone()); WeeklyCompliance::deadline($week)<$now; $week=$week->modify('+7 days')) {
            $date=$week->format('Y-m-d');
            $ids=$this->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id AND r.code='staff'
                WHERE u.created_at<=? AND (u.is_active OR EXISTS (SELECT 1 FROM user_account_status_history a WHERE a.user_id=u.id AND a.changed_at>=?)
                    OR EXISTS (SELECT 1 FROM staff_weekly_schedules s WHERE s.user_id=u.id AND s.week_start=?))
                AND NOT EXISTS (SELECT 1 FROM staff_weekly_compliance_history h WHERE h.user_id=u.id AND h.week_start=?)",
                [WeeklyCompliance::utc(WeeklyCompliance::deadline($week)),WeeklyCompliance::utc($week),$date,$date])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $total += Database::transaction($this->db,function () use ($id,$week,$now): int {
                    $this->query('SELECT id FROM users WHERE id=? FOR UPDATE',[$id])->fetchColumn();
                    return (int) $this->append((int)$id,$week,$now,null,'Weekly deadline elapsed.',false);
                });
            }
        }
        return $total;
    }

    // Schedule and leave mutations already hold the staff row and transaction.
    public function refresh(int $staff,string $from,string $until,DateTimeImmutable $now,int $actor,string $reason,bool $replace=true): void
    {
        $first=WeeklyCompliance::week(new DateTimeImmutable($from,$now->getTimezone()));
        $last=WeeklyCompliance::week(new DateTimeImmutable($until,$now->getTimezone()));
        $cutover=(new WeeklySchedules($this->db))->start();
        for ($week=max($first,new DateTimeImmutable($cutover,$now->getTimezone())); $week<=$last && WeeklyCompliance::deadline($week)<$now; $week=$week->modify('+7 days')) {
            $this->append($staff,$week,$now,$actor,$reason,$replace);
        }
    }

    private function append(int $staff,DateTimeImmutable $week,DateTimeImmutable $now,?int $actor,string $reason,bool $replace): bool
    {
        $date=$week->format('Y-m-d');
        $old=$this->query('SELECT final_result FROM staff_weekly_compliance_history WHERE user_id=? AND week_start=?
            ORDER BY id DESC LIMIT 1 FOR UPDATE',[$staff,$date])->fetchColumn();
        if ($old !== false && !$replace) {
            return false;
        }
        $policy=(new SystemConfiguration($this->db))->configuration();
        $monitor=new SubmissionMonitoring($this->db,new StaffWorkSchedules($this->db),$policy['report_deadline']);
        $row=$monitor->dashboard(['staff'=>$staff],$week,$week->modify('+6 days'),$now)['rows'][0] ?? null;
        if ($row === null) {
            return false;
        }
        $result=[
            'selected'=>$row['selected_dates'],'exemptions'=>$row['exemption_dates'],
            'expected'=>(int)$row['expected'],'submitted'=>(int)$row['submitted'],'outstanding'=>(int)$row['outstanding'],
            'percentage'=>$row['percentage']===null ? null : (float)$row['percentage'],
            'status'=>$row['compliance_status'],
        ];
        if ($old !== false && json_decode($old,true,16,JSON_THROW_ON_ERROR)==$result) {
            return false;
        }
        $this->query('INSERT INTO staff_weekly_compliance_history (user_id,week_start,final_result,reason,actor_id)
            VALUES (?,?,?,?,?)',[$staff,$date,json_encode($result,JSON_THROW_ON_ERROR),$reason,$actor]);
        if ($old !== false) {
            (new \Dtrms\Models\UserAccount($this->db))->audit('weekly_compliance_recalculated',$actor,$staff,'user',$staff,
                ['week'=>$date,'reason'=>$reason,'previous'=>json_decode($old,true,16,JSON_THROW_ON_ERROR),'new'=>$result]);
        }
        return true;
    }

    private function query(string $sql,array $params): \PDOStatement
    {
        $query=$this->db->prepare($sql);
        $query->execute($params);
        return $query;
    }
}
