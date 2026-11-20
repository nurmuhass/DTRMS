<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DateTimeImmutable;
use DateTimeZone;

final class WeeklyCompliance
{
    public static function week(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->modify('monday this week')->setTime(0, 0);
    }

    public static function deadline(DateTimeImmutable $week): DateTimeImmutable
    {
        return $week->modify('+6 days')->setTime(23, 59, 59);
    }

    public static function due(DateTimeImmutable $date, DateTimeImmutable $now): bool
    {
        $week = self::week($date);
        return $now > self::deadline($week) || $date->format('Y-m-d') < $now->format('Y-m-d');
    }

    public static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
