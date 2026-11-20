<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\Organization;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\StaffScheduleManagement;
use Dtrms\Services\WorkScheduleCalendar;

final class StaffScheduleController
{
    public const PATH = '/hr-manager/schedules';

    public function __construct(
        private Organization $organization,
        private StaffWorkSchedules $schedules,
        private StaffScheduleManagement $management,
        private BrowserSession $browser
    ) {
    }

    public function index(array $actor): string
    {
        return $this->render($actor);
    }

    public function save(array $actor): string
    {
        try {
            $count = $this->management->save($_POST, $actor, new DateTimeImmutable('today'));
            $_SESSION['schedule_notice'] = $count === 1 ? 'Staff schedule saved.' : "$count staff schedules saved.";
            $query = http_build_query(self::filters());
            return Access::redirect(self::PATH . '?' . $query);
        } catch (DomainException $error) {
            http_response_code(in_array($error->getCode(), [403, 409], true) ? $error->getCode() : 422);
            return $this->render($actor, $error->getMessage(), $_POST);
        }
    }

    private function render(array $actor, ?string $error = null, array $values = []): string
    {
        $filters = self::filters();
        $today = new DateTimeImmutable('today');
        $date = $today->format('Y-m-d');
        $listing = $this->organization->directory($filters['q'], '', 'staff', $filters['department'], $filters['page'], false);
        $person = $filters['staff'] ? $this->schedules->staff($filters['staff']) : null;
        if ($filters['staff'] && $person === null) {
            http_response_code(404);
            return View::render('error', ['title' => 'Staff member unavailable', 'message' => 'This staff schedule could not be found.']);
        }
        $ids = $person ? [$person['id']] : array_column($listing['rows'], 'id');
        $periods = $this->schedules->periods($ids, $person ? null : $date, $person ? null : '9999-12-31');
        $history = $person ? ($periods[$person['id']] ?? []) : [];
        $planned = null;
        foreach ($history as $period) {
            if (($values['replace'] ?? $_GET['replace'] ?? '') === $period['assignment_key']
                && $period['status'] === 'assigned' && $period['effective_from'] > $date) {
                $planned = $period;
                break;
            }
        }
        $calendar = new WorkScheduleCalendar($this->schedules->calendar());
        $message = $_SESSION['schedule_notice'] ?? null;
        unset($_SESSION['schedule_notice']);

        return View::render('hr/schedules', [
            'principal' => $actor, 'title' => 'Staff work schedules', 'csrf' => $this->browser->csrf(),
            'error' => $error, 'message' => $message, 'listing' => $listing, 'filters' => $filters,
            'departments' => $this->organization->departmentOptions(), 'person' => $person, 'periods' => $periods,
            'history' => $history, 'today' => $date, 'planned' => $planned, 'values' => $values,
            'earliest' => $today->modify('+1 day')->format('Y-m-d'),
            'weekdays' => OperationalPolicy::DAYS, 'defaultDays' => $calendar->defaultDays(),
        ]);
    }

    private static function filters(): array
    {
        $q = $_GET['q'] ?? '';
        return [
            'q' => is_string($q) && strlen($q) <= 400 && mb_check_encoding($q, 'UTF-8') ? trim($q) : '',
            'department' => self::positive('department'), 'staff' => self::positive('staff'),
            'page' => max(1, self::positive('page')),
        ];
    }

    private static function positive(string $key): int
    {
        return (int) filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
}
