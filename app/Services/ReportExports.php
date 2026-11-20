<?php
declare(strict_types=1);

namespace Dtrms\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use DomainException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportExports
{
    public static function individualFields(array $report, array $fields): array
    {
        return [
            ['Report reference', report_reference($report)],
            ['Staff name', $fields['reporter_name'] ?? $report['staff_name']],
            ['Staff ID', $fields['reporter_staff_code'] ?? $report['staff_code']],
            ['Department', $fields['department_name_snapshot'] ?? $report['department_name']],
            ['Reporting date', $report['report_date']],
            ['Status', \Dtrms\Models\StaffReports::STATUSES[$report['status']]],
            ['Arrival time', report_clock($fields['arrival_time'] ?? null)],
            ['Time started work', report_clock($fields['work_start_time'] ?? null)],
            ['Time ended', report_clock($fields['work_end_time'] ?? null)],
            ['Recorded work duration', isset($fields['working_minutes']) ? recorded_work_duration((int) $fields['working_minutes']) : null],
            ['Shift', !empty($fields['overnight']) ? 'Overnight' : '—'],
            ['First submitted', report_timestamp($report['first_submitted_at'])],
            ['Last submitted', report_timestamp($report['submitted_at'])],
        ];
    }

    public static function workFields(array $fields): array
    {
        return [
            ['Work description', $fields['content'] ?? null],
            ['Key achievement', $fields['achievement'] ?? null],
            ['Challenges encountered', $fields['challenges'] ?? null],
        ];
    }

    public static function history(array $approvals): array
    {
        return array_map(static fn (array $decision): array => [
            (int) $decision['revision_number'], $decision['stage'] === 'hr' ? 'HR' : 'Supervisor',
            $decision['name'], ucfirst($decision['decision']), report_timestamp($decision['created_at']), $decision['comments'],
        ], $approvals);
    }

    public static function departmentRows(array $departments): array
    {
        return array_map(static fn (array $row): array => [
            $row['month'], $row['name'], $row['staff'], $row['expected'], $row['actual'], $row['missing'],
            $row['pending'], $row['supervisor'], $row['approved'], $row['returned'], $row['attendance'], $row['minutes'], $row['late'],
            $row['completion'], $row['due_outstanding'],
        ], $departments);
    }

    public const DEPARTMENT_HEADS = ['Reporting month', 'Department', 'Staff', 'Expected submissions', 'Actual submissions',
        'Missing submissions', 'Pending review', 'Supervisor approved', 'HR approved', 'Returned', 'Attendance records',
        'Work minutes', 'Late arrivals', 'Completion (%)', 'Outstanding already due'];
    public const ATTENDANCE_HEADS = ['Report reference', 'Reporting date', 'Staff name', 'Staff ID', 'Department',
        'Staff-entered arrival', 'Work minutes', 'Arrival classification'];
    public const INDIVIDUAL_APPROVAL_HEADS = ['Submission', 'Stage', 'Reviewer', 'Decision', 'Decided at', 'Comments'];
    public const APPROVAL_HEADS = ['Stage', 'Reviewer', 'Approvals', 'Returns'];
    public const STAFF_HEADS = ['Staff name', 'Staff ID', 'Department', 'Submitted reports', 'Pending review', 'HR approved'];

    public static function pdf(array $information, array $work, array $decisions, string $organization, ?string $png): string
    {
        return self::pdfDocument(static function () use ($information, $work, $decisions, $organization, $png): void {
            require BASE_PATH . '/resources/views/analytics/pdf-report.php';
        });
    }

    public static function pdfDocument(callable $template): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        ob_start();
        try {
            $template();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(468, 805, 'Page {PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.25, 0.31, 0.40]);
        return $dompdf->output();
    }

    public static function xlsx(array $sheets, string $period): string
    {
        $cells = array_sum(array_map(static fn (array $sheet): int => count($sheet[1]) * count($sheet[2]), $sheets));
        if ($cells > 60000) {
            throw new DomainException('This workbook exceeds the export size limit. Narrow the reporting date range or filters.');
        }
        $book = new Spreadsheet();
        ob_start();
        try {
            foreach ($sheets as $index => [$name, $headers, $rows, $widths]) {
                $sheet = $index === 0 ? $book->getActiveSheet() : $book->createSheet();
                $sheet->setTitle($name);
                $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
                $sheet->mergeCells('A1:' . $lastColumn . '1');
                $sheet->mergeCells('A2:' . $lastColumn . '2');
                $sheet->setCellValueExplicit('A1', $name, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A2', $period, DataType::TYPE_STRING);
                $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0F2044');
                $sheet->getRowDimension(1)->setRowHeight(25);
                $sheet->getRowDimension(4)->setRowHeight(34);
                $sheet->freezePane('A5');
                foreach ($headers as $column => $heading) {
                    $letter = Coordinate::stringFromColumnIndex($column + 1);
                    $sheet->getColumnDimension($letter)->setWidth($widths[$column] ?? 22);
                    $sheet->setCellValueExplicit($letter . '4', $heading, DataType::TYPE_STRING);
                }
                $head = 'A4:' . $lastColumn . '4';
                $sheet->getStyle($head)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F2044');
                $sheet->getStyle($head)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($head)->getAlignment()->setWrapText(true);
                foreach ($rows as $offset => $row) {
                    foreach (array_values($row) as $column => $cell) {
                        if ($cell === null) {
                            continue;
                        }
                        $address = Coordinate::stringFromColumnIndex($column + 1) . ($offset + 5);
                        $numeric = is_int($cell) || is_float($cell);
                        $sheet->setCellValueExplicit($address, $numeric ? $cell : (string) $cell,
                            $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
                if ($rows !== []) {
                    $bottom = $lastColumn . (4 + count($rows));
                    $sheet->getStyle('A5:' . $bottom)->getAlignment()->setWrapText(true)->setVertical('top');
                    $sheet->setAutoFilter('A4:' . $bottom);
                }
            }
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->setPreCalculateFormulas(false)->save('php://output');
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
    }
}
