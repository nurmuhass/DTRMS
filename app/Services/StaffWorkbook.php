<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;
use DOMDocument;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as Reader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as Writer;
use Throwable;
use ZipArchive;

final class StaffWorkbook
{
    public const MAX_BYTES = 1024 * 1024;
    public const MAX_ROWS = 50;
    private const SHEET = 'Staff';
    private const HEADERS = ['staff_id', 'name', 'email', 'department_code'];

    public static function available(): bool
    {
        return class_exists(Spreadsheet::class) && class_exists(ZipArchive::class);
    }

    public static function template(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A2:D' . (self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        foreach (['A' => 22, 'B' => 30, 'C' => 36, 'D' => 25] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A2');

        ob_start();
        try {
            (new Writer($book))->save('php://output');
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
    }

    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new DomainException('Upload a valid .xlsx workbook.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 100) {
                throw new DomainException('The workbook contains too many archive entries.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false || ($size += $entry['size']) > 6 * self::MAX_BYTES
                    || str_contains($entry['name'], chr(92))
                    || preg_match('~^/|^[a-z]:|(?:^|/)\.\.(?:/|$)|(?:vbaProject|externalLinks|embeddings|activeX)~i', $entry['name'])) {
                    throw new DomainException('Use the plain template without links, embedded content, or macros.');
                }
                if (str_ends_with(strtolower($entry['name']), '.rels')) {
                    self::validateRelationships($zip->getFromIndex($index));
                }
            }
        } finally {
            $zip->close();
        }

        try {
            $reader = new Reader();
            $reader->setReadDataOnly(true);
            $sheets = $reader->listWorksheetInfo($path);
            if (count($sheets) !== 1 || $sheets[0]['worksheetName'] !== self::SHEET
                || $sheets[0]['totalColumns'] !== 4 || $sheets[0]['totalRows'] > self::MAX_ROWS + 1) {
                throw new DomainException('Use the Staff template with four columns and up to 50 staff rows.');
            }
            $book = $reader->load($path);
        } catch (DomainException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DomainException('The workbook could not be read. Save a fresh copy of the template as .xlsx.', 0, $error);
        }

        try {
            $sheet = $book->getActiveSheet();
            $rows = [];
            for ($line = 1; $line <= $sheets[0]['totalRows']; $line++) {
                $values = [];
                foreach (['A', 'B', 'C', 'D'] as $column) {
                    $cell = $sheet->getCell($column . $line);
                    $value = $cell->getValue();
                    if ($cell->getDataType() === DataType::TYPE_FORMULA || ($value !== null && !is_string($value))) {
                        throw new DomainException("Row $line. Enter plain text in every cell, including staff IDs.");
                    }
                    $values[] = trim($value ?? '');
                }
                if ($line === 1) {
                    if ($values !== self::HEADERS) {
                        throw new DomainException('Keep the four column headers from the template.');
                    }
                } elseif ($values !== ['', '', '', '']) {
                    $rows[$line] = $values;
                }
            }
            if ($rows === []) {
                throw new DomainException('Enter at least one staff row.');
            }
            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private static function validateRelationships(string|false $xml): void
    {
        $document = new DOMDocument();
        if ($xml === false || $xml === '' || !$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
            || $document->doctype !== null) {
            throw new DomainException('The workbook contains invalid relationships.');
        }
        foreach ($document->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
            $target = trim($relationship->getAttribute('Target'));
            if (strcasecmp($relationship->getAttribute('TargetMode'), 'external') === 0
                || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $target) || str_contains($target, chr(92))) {
                throw new DomainException('The workbook contains external relationships.');
            }
        }
    }
}
