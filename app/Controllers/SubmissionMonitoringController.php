<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\HrReviews;
use Dtrms\Models\Organization;
use Dtrms\Models\StaffReports;
use Dtrms\Models\StaffLeaves;
use Dtrms\Models\SubmissionMonitoring;
use Dtrms\Models\WorkHolidays;
use Dtrms\Models\UserAccount;
use Dtrms\Services\ReportExports;
use Dtrms\Services\ReportingAccess;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\MonitoringFilters;

final class SubmissionMonitoringController
{
    public const PATH = '/hr-manager/submissions';
    public const PERMISSION = 'hr_manage_organization';
    public const ROLE = 'hr_manager';

    public function __construct(
        private SubmissionMonitoring $monitor,
        private Organization $organization,
        private StaffReports $reports,
        private WorkHolidays $holidays,
        private StaffLeaves $staff,
        private BrowserSession $browser,
        private UserAccount $users
    ) {
    }

    public function index(array $actor): string
    {
        $now = new DateTimeImmutable();
        [$period, $error] = $this->period($now);
        $filters = self::filters($_GET);
        return $this->render($actor, 'submissions', 'Staff Submission Monitoring', [
            'period' => $period, 'error' => $error, 'filters' => $filters, 'states' => SubmissionMonitoring::STATES,
            'sorts' => SubmissionMonitoring::SORTS, 'canExport' => ReportingAccess::exports($actor),
            'listing' => $this->monitor->dashboard($filters, $period['from'], $period['until'], $now),
            'departments' => $this->organization->departmentOptions(), 'supervisors' => $this->organization->supervisorOptions(),
            'staffOptions' => $this->staff->staffOptions(),
        ]);
    }

    public function export(array $actor): string
    {
        if (!ReportingAccess::exports($actor)) {
            http_response_code(403);
            return View::render('error', ['title' => 'Access denied', 'message' => 'Export permission is required.']);
        }
        if (!class_exists(Spreadsheet::class)) {
            http_response_code(503);
            return View::render('error', ['title' => 'Export unavailable', 'message' => 'The Excel export library is unavailable.']);
        }

        try {
            $now = new DateTimeImmutable();
            $period = MonitoringFilters::period($_POST, $now);
            $filters = self::filters($_POST);
            $listing = $this->monitor->dashboard($filters, $period['from'], $period['until'], $now, export: true);
            if (count($listing['rows']) !== $listing['count']) {
                throw new DomainException('The export exceeds 2,000 staff. Narrow your filters and try again.');
            }
            $rows = array_map(static fn (array $row): array => [
                $row['name'], $row['staff_id'], $row['department'], $row['supervisor'], $row['selected_dates'],
                $row['exemption_dates'], $row['missing_weeks'] && !$row['confirmed_weeks'] && !$row['expected'] ? 'Not set' : (int) $row['expected'],
                (int) $row['actual'], (int) $row['submitted'], (int) $row['outstanding'], (int) $row['due_outstanding'],
                $row['missing_weeks'] ? 'Schedule Not Submitted' : ($row['schedule_states'] ?? 'Legacy calendar'),
                $row['percentage'] === null ? null : (float) $row['percentage'],
                SubmissionMonitoring::STATES[$row['compliance_status']], (int) $row['approved'], (int) $row['returned'],
            ], $listing['rows']);
            $from = $period['from']->format('Y-m-d');
            $until = $period['until']->format('Y-m-d');
            $workbook = ReportExports::xlsx([['Staff submission monitoring', [
                'Staff name', 'Staff ID', 'Department', 'Supervisor', 'Selected dates', 'Approved leave exemptions',
                'Expected', 'Submitted', 'Matched submissions', 'Outstanding', 'Due and outstanding',
                'Schedule status', 'Compliance (%)', 'Submission status', 'HR approved', 'Returned',
            ], $rows, [28, 16, 25, 28, 40, 40]]], "$from to $until");
        } catch (DomainException $error) {
            http_response_code(422);
            return View::render('error', ['title' => 'Export unavailable', 'message' => $error->getMessage()]);
        }

        $this->users->audit('submission_monitoring_exported', (int) $actor['id'], null, 'submission_monitoring', null, [
            'from' => $from, 'until' => $until, 'filters' => array_diff_key($filters, ['page' => true]), 'rows' => $listing['count'],
        ]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="staff-submission-monitoring-' . $from . '.xlsx"');
        header('Cache-Control: no-store');
        return $workbook;
    }

    private static function filters(array $query): array
    {
        $search = $query['q'] ?? '';
        $status = $query['status'] ?? '';
        $leave = $query['leave'] ?? '';
        $schedule = $query['schedule'] ?? '';
        $sort = $query['sort'] ?? '';
        return [
            'q' => is_string($search) && strlen($search) <= 400 && mb_check_encoding($search, 'UTF-8') ? trim($search) : '',
            'department' => self::id($query['department'] ?? null), 'supervisor' => self::id($query['supervisor'] ?? null),
            'status' => is_string($status) && isset(SubmissionMonitoring::STATES[$status]) ? $status : '',
            'leave' => in_array($leave, ['yes', 'no'], true) ? $leave : '', 'page' => self::id($query['page'] ?? null),
            'sort' => is_string($sort) && isset(SubmissionMonitoring::SORTS[$sort]) ? $sort : 'name',
            'staff' => self::id($query['staff'] ?? null), 'schedule' => in_array($schedule,['confirmed','missing'],true) ? $schedule : '',
        ];
    }

    public function report(array $actor): string
    {
        $staff = self::id($_GET['staff'] ?? null);
        $id = self::id($_GET['id'] ?? null);
        if (!Access::permits($actor, HrReviews::READ, self::ROLE)) {
            http_response_code(403);
            return View::render('error', ['title' => 'Access denied', 'message' => 'Report review permission is required.']);
        }
        $person = $this->monitor->person($staff);
        $report = $person ? $this->reports->find($staff, $id) : null;
        if (!$report || !$this->monitor->isSubmitted($report['status'])) {
            return $this->missing();
        }
        return $this->render($actor, 'submission-report', 'Submitted daily report', [
            'staff' => $person, 'report' => $report,
            'revisions' => $this->reports->revisions($staff, $id), 'approvals' => $this->reports->approvals($staff, $id),
        ]);
    }

    public function holidayList(array $actor, ?string $error = null): string
    {
        $year = $_GET['year'] ?? (new DateTimeImmutable())->format('Y');
        if (!is_string($year) || !preg_match('/\A[1-9][0-9]{3}\z/', $year)) {
            http_response_code(422);
            $error = 'Choose a valid calendar year.';
            $year = (new DateTimeImmutable())->format('Y');
        }
        return $this->render($actor, 'work-holidays', 'Non-working holidays', [
            'error' => $error, 'year' => $year, 'holidays' => $this->holidays->between("$year-01-01", "$year-12-31"),
            'departments' => $this->organization->departmentOptions(),
        ]);
    }

    public function holidayChange(array $actor): string
    {
        try {
            $action = $_POST['action'] ?? null;
            if ($action === 'add') {
                $department = $_POST['department'] ?? '';
                $id = self::id($department);
                if ($department !== '' && !$id) {
                    throw new DomainException('Choose a valid department or organization-wide scope.');
                }
                $date = $_POST['date'] ?? null;
                $this->holidays->add($date, $_POST['name'] ?? null, $id, (int) $actor['id']);
                return Access::redirect(self::PATH . '/holidays?year=' . substr($date, 0, 4));
            }
            if ($action === 'remove') {
                $this->holidays->remove(self::id($_POST['id'] ?? null), (int) $actor['id']);
                return Access::redirect(self::PATH . '/holidays');
            }
            throw new DomainException('Choose a holiday action.');
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->holidayList($actor, $error->getMessage());
        }
    }

    private function period(DateTimeImmutable $now): array
    {
        try {
            return [MonitoringFilters::period($_GET, $now), null];
        } catch (DomainException $error) {
            http_response_code(422);
            return [MonitoringFilters::period([], $now), $error->getMessage()];
        }
    }

    private function render(array $actor, string $view, string $title, array $data): string
    {
        return View::render('hr/' . $view, $data + ['principal' => $actor, 'title' => $title,
            'csrf' => $this->browser->csrf(), 'error' => null, 'message' => null]);
    }

    private function missing(): string
    {
        http_response_code(404);
        return View::render('error', ['title' => 'Staff submission unavailable', 'message' => 'This staff member or submitted report could not be found.']);
    }

    private static function id(mixed $value): int
    {
        return (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
}
