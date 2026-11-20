<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DomainException;

final class MonitoringFilters
{
    public static function period(array $query, DateTimeImmutable $now): array
    {
        $mode = $query['period'] ?? 'week';
        if ($mode === 'week') {
            $week = $query['week'] ?? $now->format('o-\WW');
            if (!is_string($week) || !preg_match('/\A([1-9][0-9]{3})-W([0-9]{2})\z/', $week, $parts)) {
                throw new DomainException('Choose a valid reporting week.');
            }
            $first = $now->setISODate((int) $parts[1], (int) $parts[2])->setTime(0, 0);
            if ($first->format('o-\WW') !== $week) {
                throw new DomainException('Choose a valid reporting week.');
            }
            $last = $first->modify('+6 days');
        } elseif ($mode === 'month') {
            $month = $query['month'] ?? $now->format('Y-m');
            if (!is_string($month) || !preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/', $month)) {
                throw new DomainException('Choose a valid reporting month.');
            }
            $first = (new DateTimeImmutable($month . '-01', $now->getTimezone()))->setTime(0, 0);
            $last = $first->modify('last day of this month');
        } else {
            throw new DomainException('Choose a week or month.');
        }
        return ['mode' => $mode, 'from' => $first, 'until' => $last, 'week' => $first->format('o-\WW'),
            'month' => $first->format('Y-m')];
    }
}
