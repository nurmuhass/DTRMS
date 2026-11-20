<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\WeeklySchedules;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\MonitoringFilters;
use Dtrms\Services\WeeklyCompliance;

final class WeeklyScheduleController
{
    public function __construct(private WeeklySchedules $schedules,private StaffLeaves $staff,private BrowserSession $browser)
    {
    }

    public function show(array $actor): string
    {
        return $this->render($actor);
    }

    public function save(array $actor): string
    {
        try {
            [$now,$week,$id,$path,$hr] = $this->context($actor);
            $action = $_POST['action'] ?? '';
            $reason = $_POST['reason'] ?? '';
            if (!$id || !is_string($action) || !is_string($reason)) {
                throw new DomainException('Choose a staff member and a valid schedule action.');
            }
            $this->schedules->change($id,(int) $actor['id'],$week,$_POST['days'] ?? [],$action,trim($reason),$hr,$now);
            $_SESSION['weekly_notice'] = 'Weekly work schedule saved.';
            return Access::redirect($path . '?' . http_build_query(['week'=>$week->format('o-\WW')]+($hr ? ['staff'=>$id] : [])));
        } catch (DomainException $error) {
            return $this->render($actor,$error);
        }
    }

    private function context(array $actor): array
    {
        $now = new DateTimeImmutable();
        $hr = $actor['role_code'] === 'hr_manager';
        if (!$hr && isset($_GET['staff']) && (int)filter_var($_GET['staff'],FILTER_VALIDATE_INT)!==(int)$actor['id']) {
            throw new DomainException('Staff schedule unavailable.',404);
        }
        $first = $this->schedules->start();
        $default = max(WeeklyCompliance::week($now)->format('Y-m-d'),$first);
        if (!$hr && !isset($_GET['week']) && $default<= $now->format('Y-m-d')) {
            $default = (new DateTimeImmutable($default))->modify('+7 days')->format('Y-m-d');
        }
        $week = MonitoringFilters::period(['period'=>'week','week'=>$_GET['week'] ?? (new DateTimeImmutable($default))->format('o-\WW')],$now)['from'];
        $id = $hr ? (int) filter_var($_GET['staff'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : (int) $actor['id'];
        return [$now,$week,$id,$hr ? '/hr-manager/weekly-schedules' : '/staff/schedule',$hr];
    }

    private function render(array $actor,?DomainException $error = null): string
    {
        try {
            [$now,$week,$id,$path,$hr] = $this->context($actor);
        } catch (DomainException $invalid) {
            http_response_code($invalid->getCode()===404 ? 404 : 422);
            return View::render('error',['title'=>'Invalid week','message'=>$invalid->getMessage()]);
        }
        if ($error) {
            http_response_code(in_array($error->getCode(),[404,409],true) ? $error->getCode() : 422);
        }
        $row = $id ? $this->schedules->get($id,$week->format('Y-m-d')) : null;
        $message = $_SESSION['weekly_notice'] ?? null;
        unset($_SESSION['weekly_notice']);
        return View::render('records/weekly-schedule',[
            'principal'=>$actor,'title'=>$hr ? 'Weekly schedule management' : 'My Weekly Work Schedule',
            'csrf'=>$this->browser->csrf(),'error'=>$error?->getMessage(),'message'=>$message,
            'hr'=>$hr,'id'=>$id,'path'=>$path,'week'=>$week,'row'=>$row,'start'=>$this->schedules->start(),
            'editable'=>$hr || ($week>$now && !in_array($row['status'] ?? '',['locked','cancelled'],true)),
            'effectiveStatus'=>!empty($row['unlocked_at']) ? 'HR unlocked' :
                ($row && in_array($row['status'],WeeklySchedules::CONFIRMED,true) && $week<=$now ? 'locked' : ($row['status'] ?? null)),
            'staff'=>$hr ? $this->staff->staffOptions() : [],
            'history'=>$row ? $this->schedules->history($id,(int) $row['id']) : [],
        ]);
    }
}
