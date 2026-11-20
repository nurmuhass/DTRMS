<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function report_reference(array $report): string
{
    return $report['reference_number'] ?? ('DTR-LEGACY-' . $report['id']);
}

function recorded_work_duration(int $minutes): string
{
    return sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60);
}

function report_clock(?string $time): string
{
    return $time === null ? '' : substr($time, 0, 5);
}

function report_timestamp(?string $utc): string
{
    if ($utc === null) {
        return '—';
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('d M Y, H:i');
}

function report_local_date(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d');
}
