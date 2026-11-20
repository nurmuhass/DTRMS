<?php
declare(strict_types=1);

namespace Dtrms\Services;

use Dtrms\Models\StaffReports;

final class StaffPeriodExports
{
    private const REPORT_HEADS = ['Reference', 'Report date', 'Department', 'Approval status', 'First submitted', 'Last submitted',
        'Arrival', 'Started', 'Ended', 'Work minutes', 'Work description', 'Achievements', 'Challenges'];
    private const APPROVAL_HEADS = ['Reference', 'Report date', 'Revision', 'Stage', 'Decision', 'Reviewer', 'Decided at', 'Comments'];

    public static function xlsx(array $bundle): string
    {
        return ReportExports::xlsx([
            ['Summary', ['Field', 'Value'], self::summary($bundle), [34, 45]],
            ['Daily reports', self::REPORT_HEADS, array_map(self::reportRow(...), $bundle['reports']),
                [34, 15, 28, 26, 23, 23, 12, 12, 12, 14, 60, 45, 45]],
            ['Approval history', self::APPROVAL_HEADS, array_map(self::approvalRow(...), $bundle['approvals']),
                [34, 15, 12, 15, 16, 25, 23, 55]],
            ['Leave exemptions', ['Reporting date', 'Exemption'], array_map(static fn (string $date): array => [$date, 'Approved leave'], $bundle['exemptions']), [20, 30]],
        ], self::period($bundle));
    }

    public static function pdf(array $bundle, string $organization): string
    {
        $summary = self::summary($bundle);
        $title = self::period($bundle);
        $reports = array_map(self::reportRow(...), $bundle['reports']);
        $decisionsByReference = [];
        foreach ($bundle['approvals'] as $approval) {
            $decisionsByReference[report_reference($approval)][] = self::approvalRow($approval);
        }
        $exemptions = $bundle['exemptions'];
        return ReportExports::pdfDocument(static function () use ($organization, $summary, $title, $reports, $decisionsByReference, $exemptions): void {
            require BASE_PATH . '/resources/views/records/period-pdf.php';
        });
    }

    private static function period(array $bundle): string
    {
        return ($bundle['period']['mode'] === 'week' ? 'Weekly' : 'Monthly') . ' staff record · '
            . $bundle['period']['from']->format('Y-m-d') . ' to ' . $bundle['period']['until']->format('Y-m-d');
    }

    private static function summary(array $bundle): array
    {
        $staff = $bundle['staff'];
        $current = $bundle['current'];
        return [
            ['Staff name', $staff['name']], ['Staff ID', $staff['staff_id']], ['Current department', $staff['department']],
            ['Assigned supervisor', $staff['supervisor']], ['Account status', $staff['is_active'] ? 'Active' : 'Inactive'],
            ['Current work schedule', $staff['schedule']], ['Current leave', $staff['leave_until'] ? 'Approved through ' . $staff['leave_until'] : 'No approved leave today'],
            ['Selected work dates in period', $current['selected_dates']], ['Weeks missing a schedule', (int)$current['missing_weeks']],
            ['Expected submissions', $current['missing_weeks'] && !$current['confirmed_weeks'] && !$current['expected'] ? 'Not set' : (int) $current['expected']],
            ['Actual submissions', (int) $current['actual']],
            ['Submitted for due dates', (int) $current['submitted']], ['Outstanding submissions', (int) $current['outstanding']],
            ['Compliance (%)', $current['percentage'] === null ? 'N/A' : (float) $current['percentage']],
            ['Compliance status', $bundle['states'][$current['compliance_status']]],
            ['Final HR approvals', (int) $current['approved']], ['Approved leave exemptions', count($bundle['exemptions'])],
            ['Reports with recorded durations', $bundle['attendance']['count']], ['Staff-entered work minutes', $bundle['attendance']['minutes']],
        ];
    }

    private static function reportRow(array $report): array
    {
        return [
            report_reference($report), $report['report_date'], $report['department'], StaffReports::STATUSES[$report['status']],
            report_timestamp($report['first_submitted_at']), report_timestamp($report['submitted_at']),
            report_clock($report['arrival_time']), report_clock($report['work_start_time']), report_clock($report['work_end_time']),
            $report['working_minutes'] !== null ? (int) $report['working_minutes'] : null,
            $report['content'], $report['achievement'], $report['challenges'],
        ];
    }

    private static function approvalRow(array $record): array
    {
        return [report_reference($record), $record['report_date'], (int) $record['revision_number'],
            $record['stage'] === 'hr' ? 'HR' : 'Supervisor', ucfirst($record['decision']), $record['reviewer'],
            report_timestamp($record['created_at']), $record['comments']];
    }
}
