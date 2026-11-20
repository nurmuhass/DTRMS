<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use Dtrms\Core\View;
use Dtrms\Core\HttpError;
use Dtrms\Middleware\Access;
use Dtrms\Models\HrReviews;
use Dtrms\Models\Notifications;
use Dtrms\Services\BrowserSession;

final class NotificationController
{
    public function __construct(
        private Notifications $notifications,
        private HrReviews $reviews,
        private BrowserSession $browser
    )
    {
    }

    public function inbox(array $actor): string
    {
        $page = max(1, (int) filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT));
        $listing = $this->notifications->inbox((int) $actor['id'], $page);
        $listing['rows'] = $this->links($actor, $listing['rows']);
        return View::render('notifications', [
            'title' => 'Notifications', 'principal' => $actor, 'csrf' => $this->browser->csrf(),
            'listing' => $listing, 'unread' => $this->notifications->unread((int) $actor['id']),
        ]);
    }

    public function read(array $actor): string
    {
        $id = (int) filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            return HttpError::response(422, 'invalid_notification', 'Choose a valid notification.');
        }
        if (!$this->notifications->read((int) $actor['id'], $id)) {
            return HttpError::response(404, 'not_found', 'This notification could not be found.');
        }
        return $this->writeResponse($actor);
    }

    public function readAll(array $actor): string
    {
        $this->notifications->readAll((int) $actor['id']);
        return $this->writeResponse($actor);
    }

    public function feed(array $actor): string
    {
        $id = (int) $actor['id'];
        return $this->json([
            'unread' => $this->notifications->unread($id),
            'items' => array_map(static fn (array $item): array => [
                'id' => (int) $item['id'], 'message' => $item['message'], 'url' => $item['url'],
                'unread' => $item['read_at'] === null, 'date' => report_timestamp($item['created_at']),
            ], $this->links($actor, $this->notifications->recent($id))),
        ]);
    }

    private function writeResponse(array $actor): string
    {
        return HttpError::wantsJson()
            ? $this->feed($actor) : Access::redirect('/notifications');
    }

    private function json(array $data): string
    {
        header('Content-Type: application/json; charset=UTF-8');
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function links(array $actor, array $rows): array
    {
        $staff = Access::permits($actor, 'staff_workspace', 'staff');
        $supervisor = Access::permits($actor, 'supervisor_workspace', 'supervisor');
        $hr = Access::permits($actor, HrReviews::READ, HrReviews::ROLES);
        $hrReportIds = [];
        if ($hr) {
            foreach ($rows as $row) {
                if ($row['type'] === 'report_pending_hr' && $row['report_id'] !== null) {
                    $hrReportIds[] = (int) $row['report_id'];
                }
            }
        }
        $viewable = array_fill_keys($this->reviews->viewableIds($hrReportIds), true);
        foreach ($rows as &$row) {
            $id = (int) $row['report_id'];
            $row['url'] = match (true) {
                $id > 0 && $staff && (int) $row['staff_id'] === (int) $actor['id'] => '/staff/reports/view?id=' . $id,
                $id > 0 && $supervisor && (int) $row['supervisor_id'] === (int) $actor['id'] => '/supervisor/report?id=' . $id,
                isset($viewable[$id]) && $row['type'] === 'report_pending_hr' => '/hr/reports/view?id=' . $id,
                $staff && $row['type'] === 'reminder_missing_report' => '/staff/reports/edit',
                $staff && $row['type'] === 'weekly_schedule_missing' => '/staff/schedule',
                $staff && in_array($row['type'],['weekly_date_passed','weekly_date_approaching','weekly_reports_pending','weekly_reports_overdue'],true)
                    => '/reports/staff-record',
                $supervisor && $row['type'] === 'reminder_supervisor' => '/supervisor/reports',
                $hr && $row['type'] === 'reminder_hr' => '/hr/reports',
                default => null,
            };
        }
        return $rows;
    }
}
