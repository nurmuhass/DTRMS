<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\View;
use Dtrms\Models\ReportAnalytics;
use Dtrms\Models\StaffReports;
use Dtrms\Models\SystemConfiguration;
use Dtrms\Models\TechnicalMonitoring;
use Dtrms\Models\UserAccount;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\ReportExports;
use Dtrms\Services\ReportFilters;
use Dtrms\Services\ReportingAccess;
use Dtrms\Services\ReportingSummary;

final class ReportingController
{
    public function __construct(
        private ReportAnalytics $records,
        private ReportingSummary $summary,
        private StaffReports $staffReports,
        private SystemConfiguration $settings,
        private TechnicalMonitoring $monitoring,
        private BrowserSession $browser,
        private array $policy,
        private UserAccount $users
    ) {
    }

    public function dashboard(array $actor): string
    {
        $scope = ReportingAccess::scope($actor);
        if ($scope === null) {
            return $this->error(403, 'Your role does not have access to report analytics.');
        }
        $now = new DateTimeImmutable();
        try {
            $filters = ReportFilters::parse($_GET, $now);
            $result = $this->summary->build($scope, (int) $actor['id'], $filters, $now);
        } catch (DomainException $error) {
            return $this->error(422, $error->getMessage());
        }

        $departments = $result['departments'];
        usort($departments, static fn (array $a, array $b): int => $b['actual'] + $b['missing'] <=> $a['actual'] + $a['missing']);
        $departments = array_slice($departments, 0, 10);
        $stats = $result['stats'];
        $charts = [
            'daily' => ['line', array_keys($result['days']), ['Submitted reports' => array_values($result['days'])]],
            'monthly' => ['bar', array_keys($result['monthly']), ['Submitted reports' => array_values($result['monthly'])]],
            'departments' => ['bar', array_map(static fn (array $d): string => $d['month'] . ' · ' . $d['name'], $departments),
                ['Submitted' => array_column($departments, 'actual'), 'Missing' => array_column($departments, 'missing')]],
            'pending' => ['bar', ['Pending supervisor', 'Pending HR'], ['Reports' => [$stats['pending_supervisor'], $stats['pending_hr']]]],
            'arrival' => ['bar', ['On time', 'Late'], ['Assessed arrivals' => [$stats['assessed'] - $stats['late'], $stats['late']]]],
        ];
        return View::render('analytics/dashboard', [
            'title' => 'Reporting and analytics', 'principal' => $actor, 'csrf' => $this->browser->csrf(),
            'filters' => $filters, 'scope' => $scope, 'result' => $result, 'charts' => $charts,
            'options' => $this->records->options($scope, (int) $actor['id']), 'exports' => ReportingAccess::exports($actor),
        ]);
    }

    public function export(array $actor): string
    {
        $scope = ReportingAccess::scope($actor);
        if ($scope === null || !ReportingAccess::exports($actor)) {
            return $this->error(403, 'Your role does not have permission to export these reports.');
        }
        $format = $_GET['format'] ?? 'xlsx';
        if (!in_array($format, ['xlsx', 'pdf'], true)) {
            return $this->error(422, 'Choose PDF or Excel.');
        }
        if (($format === 'xlsx' && !class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))
            || ($format === 'pdf' && !class_exists(\Dompdf\Dompdf::class))) {
            return $this->error(503, 'The export library is unavailable.');
        }
        $now = new DateTimeImmutable();
        try {
            if (array_key_exists('id', $_GET)) {
                $id = (int) filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $record = $id > 0 ? ($this->records->rows($scope, (int) $actor['id'], ReportFilters::parse([], $now), $id)[0] ?? null) : null;
                if ($record === null) {
                    return $this->error(404, 'This report is unavailable.');
                }
                return $this->individual($actor, $scope, $record, $format);
            }
            if ($format !== 'xlsx') {
                return $this->error(422, 'Choose an individual report for PDF export.');
            }
            $type = $_GET['type'] ?? 'department';
            if (!in_array($type, ['staff', 'department', 'attendance', 'approval'], true)) {
                return $this->error(422, 'Choose a valid workbook.');
            }
            $filters = ReportFilters::parse($_GET, $now);
            $result = $this->summary->build($scope, (int) $actor['id'], $filters, $now);
            $sheet = match ($type) {
                'department' => ['Departmental monthly summary', ReportExports::DEPARTMENT_HEADS,
                    ReportExports::departmentRows($result['departments']), [16, 30]],
                'attendance' => ['Staff-entered attendance', ReportExports::ATTENDANCE_HEADS, $result['attendance'], [34, 16, 28, 16, 24, 19, 18, 23]],
                'approval' => ['Approval activity', ReportExports::APPROVAL_HEADS, $result['approvals'], [18, 30, 18, 18]],
                default => ['Staff submissions', ReportExports::STAFF_HEADS, array_map(static fn (array $person): array => [
                    $person['staff_name'], $person['staff_code'], $person['department_name'],
                    $person['submitted'], $person['pending'], $person['approved'],
                ], $result['people']), [30, 18, 28]],
            };
            $period = $filters->from . ' to ' . $filters->until . ' · DTRMS reporting dates';
            if ($type === 'department') {
                $period .= ' · All workflow states';
            } elseif ($filters->status !== '') {
                $period .= ' · ' . StaffReports::STATUSES[$filters->status];
            }
            $workbook = ReportExports::xlsx([$sheet], $period);
            $this->users->audit('reporting_summary_exported', (int) $actor['id'], null, 'reporting_summary', null, [
                'scope' => $scope, 'type' => $type, 'from' => $filters->from, 'until' => $filters->until,
                'status' => $type === 'department' ? '' : $filters->status, 'dimensions' => $filters->dimensions,
            ]);
            return $this->attachment($workbook, 'dtrms-' . $type . '-' . $filters->from . '.xlsx', 'xlsx');
        } catch (DomainException $error) {
            return $this->error(422, $error->getMessage());
        }
    }

    public function administrative(array $actor): string
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return $this->error(503, 'The export library is unavailable.');
        }
        $model = $this->monitoring->dashboard();
        $labels = ['users' => 'Registered users', 'active' => 'Active accounts', 'inactive' => 'Inactive accounts',
            'total' => 'Total reports', 'pending_supervisor' => 'Pending supervisor', 'pending_hr' => 'Pending HR', 'approved' => 'Completed reports',
            'returned' => 'Returned reports', 'failed_logins' => 'Failed logins in the last 24 hours', 'failed_email' => 'Email failures in the last 24 hours'];
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = [$label, (int) $model['counts'][$key]];
        }
        $departments = array_map(static fn (array $department): array => [
            $department['name'], (int) $department['submitted'], (int) $department['pending_supervisor'],
            (int) $department['pending_hr'], (int) $department['approved'], (int) $department['returned'],
        ], $model['departments']);
        $workbook = ReportExports::xlsx([
            ['Operational totals', ['Metric', 'Count'], $rows, [42, 18]],
            ['Department activity', ['Department', 'Submitted', 'Pending supervisor', 'Pending HR', 'Completed', 'Returned'], $departments, [32]],
        ], 'Operational snapshot · ' . (new DateTimeImmutable())->format('Y-m-d'));
        $this->users->audit('administrative_summary_exported', (int) $actor['id'], null, 'reporting_summary');
        return $this->attachment($workbook, 'dtrms-administrative.xlsx', 'xlsx');
    }

    private function individual(array $actor, string $scope, array $report, string $format): string
    {
        $id = (int) $report['id'];
        $staff = (int) $report['staff_id'];
        $approvals = ReportExports::history($this->staffReports->approvals($staff, $id));
        $fields = $report;
        if ($scope !== 'staff') {
            $revisions = $this->staffReports->revisions($staff, $id);
            $fields = $revisions[0]['fields'] ?? ['content' => $report['content']];
        }
        $facts = ReportExports::individualFields($report, $fields);
        $work = ReportExports::workFields($fields);
        if ($format === 'pdf') {
            $organization = $this->policy['organization_name'] ?: $this->policy['app_name'];
            $bytes = ReportExports::pdf($facts, $work, $approvals, $organization, $this->settings->logo());
        } else {
            $bytes = ReportExports::xlsx([
                ['Daily report', ['Field', 'Value'], array_merge($facts, $work), [28, 72]],
                ['Approval history', ReportExports::INDIVIDUAL_APPROVAL_HEADS, $approvals, [14, 18, 28, 18, 24, 62]],
            ], $report['report_date'] . ' · DTRMS report and approval records');
        }
        $this->users->audit('daily_report_exported', (int) $actor['id'], $staff, 'daily_report', $id, [
            'format' => $format, 'scope' => $scope,
        ]);
        return $this->attachment($bytes, 'daily-report-' . $id . '.' . $format, $format);
    }

    private function attachment(string $bytes, string $filename, string $format): string
    {
        header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        return $bytes;
    }

    private function error(int $status, string $message): string
    {
        http_response_code($status);
        return View::render('error', ['title' => 'Reporting request unavailable', 'message' => $message]);
    }
}
