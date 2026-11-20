<?php
declare(strict_types=1);

namespace Dtrms\Services;

final class WorkScheduleCalendar
{
    public const WEEKLY_DAYS = 3;
    private array $default;

    public function __construct(private array $baseline)
    {
        $this->default = array_slice(array_values(array_unique([...$baseline['working_days'], ...range(1, 7)])), 0, self::WEEKLY_DAYS);
    }

    public function expects(string $date, int $weekday, array $periods): bool
    {
        return in_array($weekday, $this->daysOn($date, $periods), true);
    }

    public function daysOn(string $date, array $periods): array
    {
        foreach ($periods as $period) {
            if ($period['effective_from'] <= $date && ($period['effective_to'] === null || $period['effective_to'] >= $date)) {
                return $period['days'];
            }
        }
        return $date < $this->baseline['effective_from'] ? $this->baseline['working_days'] : $this->default;
    }

    public function defaultDays(): array
    {
        return $this->default;
    }
}
