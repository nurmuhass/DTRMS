<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use Dtrms\Core\Database;
use Dtrms\Models\Notifications;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Models\WeeklySchedules;
use PDO;

final class WeeklyReminders
{
    public function __construct(private PDO $db,private Notifications $notifications)
    {
    }

    public function run(DateTimeImmutable $now,array $policy): array
    {
        $schedules=new WeeklySchedules($this->db);
        $locked=$schedules->lockStarted($now);
        $finalized=(new WeeklyResults($this->db))->finalize($now);
        if (!$policy['reminders_enabled'] || $now->format('H:i') < $policy['reminder_time']) {
            return compact('locked','finalized')+['queued'=>0];
        }
        $week=WeeklyCompliance::week($now);
        $monitor=new SubmissionMonitoring($this->db,new StaffWorkSchedules($this->db),$policy['report_deadline']);
        $queued=Database::transaction($this->db,function () use ($now,$week,$monitor,$schedules): int {
            $count=0;
            foreach ([$week->modify('-7 days'),$week,$week->modify('+7 days')] as $selected) {
                $key=$selected->format('Y-m-d');
                if ($key<$schedules->start()) {
                    continue;
                }
                $closed=WeeklyCompliance::deadline($selected)<$now;
                $future=$selected>$week;
                if ($future && (int)$now->format('N')<5) {
                    continue;
                }
                $page=1;
                do {
                    $result=$monitor->dashboard(['page'=>$page],$selected,$selected->modify('+6 days'),$now);
                    foreach ($result['rows'] as $row) {
                        if (!$row['is_active']) {
                            continue;
                        }
                        $event=match(true) {
                            $row['missing_weeks']>0 && !$closed => 'weekly_schedule_missing',
                            $row['outstanding']>0 && $closed => 'weekly_reports_overdue',
                            $row['outstanding']>0 && !$future && (int)$now->format('N')>=6 => 'weekly_reports_pending',
                            default=>null,
                        };
                        if ($event) {
                            $count+=(int)$this->notifications->publish((int)$row['id'],$event,null,$key);
                        }
                    }
                } while ($page++<$result['pages']);
            }
            foreach ($monitor->selectedMissingDates($now->modify('-1 day')->setTime(0,0),$now->modify('+1 day')->setTime(0,0),$now) as $row) {
                $event=$row['work_date']<$now->format('Y-m-d') ? 'weekly_date_passed' : 'weekly_date_approaching';
                $count+=(int)$this->notifications->publish((int)$row['user_id'],$event,null,$row['work_date']);
            }
            return $count;
        });
        return compact('locked','finalized','queued');
    }
}
