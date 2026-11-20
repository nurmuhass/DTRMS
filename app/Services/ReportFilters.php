<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DomainException;
use Dtrms\Models\StaffReports;

final class ReportFilters
{
    private function __construct(
        public readonly string $from,
        public readonly string $until,
        public readonly string $status,
        public readonly array $dimensions,
        public readonly array $values
    ) {
    }

    public static function parse(array $input, DateTimeImmutable $now): self
    {
        $values = [];
        foreach (['from', 'until', 'month', 'year', 'status', 'department', 'staff', 'supervisor'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > 40) {
                throw new DomainException('A reporting filter is invalid.');
            }
            $values[$key] = trim($value);
        }

        $from = $values['from'];
        $until = $values['until'];
        $month = $values['month'];
        $year = $values['year'];
        if (($from === '') !== ($until === '') || ($from !== '' && (!DailyReporting::validDate($from) || !DailyReporting::validDate($until)))) {
            throw new DomainException('Enter both dates in a valid reporting range.');
        }
        if (($year !== '' && !preg_match('/\A[1-9][0-9]{3}\z/', $year))
            || ($month !== '' && !preg_match('/\A(?:0?[1-9]|1[0-2])\z/', $month))) {
            throw new DomainException('Choose a valid reporting month and four-digit year.');
        }

        if ($month !== '') {
            $year = $year ?: $now->format('Y');
            $values['year'] = $year;
            $values['month'] = (string) (int) $month;
            $start = sprintf('%s-%02d-01', $year, (int) $month);
            $end = (new DateTimeImmutable($start))->format('Y-m-t');
        } elseif ($year !== '') {
            $start = $year . '-01-01';
            $end = $year . '-12-31';
        } else {
            $start = $now->format('Y-m-01');
            $end = $now->format('Y-m-t');
        }

        if ($from === '') {
            [$from, $until] = [$start, $end];
        } elseif ($month !== '' || $year !== '') {
            $from = max($from, $start);
            $until = min($until, $end);
        }
        if ($from > $until || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($until))->days > 365) {
            throw new DomainException('Choose an overlapping reporting period of no more than 366 calendar days.');
        }

        $status = $values['status'];
        if ($status !== '' && !isset(StaffReports::STATUSES[$status])) {
            throw new DomainException('Choose a valid approval status.');
        }
        $dimensions = [];
        foreach (['department', 'staff', 'supervisor'] as $key) {
            $dimensions[$key] = 0;
            if ($values[$key] !== '') {
                $id = filter_var($values[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new DomainException('Choose a valid department, staff member, or supervisor.');
                }
                $dimensions[$key] = $id;
            }
        }
        return new self($from, $until, $status, $dimensions, $values);
    }

    public function matchesStatus(string $status): bool
    {
        return match ($this->status) {
            '' => true,
            'pending_hr', 'supervisor_approved' => in_array($status, ['pending_hr', 'supervisor_approved'], true),
            'returned' => in_array($status, DailyReporting::RETURNED, true),
            default => $status === $this->status,
        };
    }
}
