<?php
declare(strict_types=1);

use Dtrms\Models\Notifications;
use Dtrms\Models\ReportAnalytics;
use Dtrms\Models\StaffWorkSchedules;
use Dtrms\Services\OperationalPolicy;
use Dtrms\Services\ReportFilters;
use Dtrms\Services\ReportReminders;
use Dtrms\Services\ReportingSummary;
use Dtrms\Services\StaffScheduleManagement;
use Dtrms\Services\WorkScheduleCalendar;

$schedulePath = '/hr-manager/schedules';
$monday = new DateTimeImmutable('next monday');
$start = $monday->format('Y-m-d');
$end = $monday->modify('+13 days')->format('Y-m-d');
$scheduleCsrf = $hr->request($schedulePath, 200)->csrf();
$portal->request($schedulePath, 403);
$officerBrowser->request($schedulePath, 403);
$adminBrowser->request($schedulePath, 403);
$portal->request($schedulePath, 403, ['csrf' => $portal->request('/', 200)->csrf()]);
$hr->request($schedulePath, 403, ['csrf' => 'invalid']);
$hr->request($schedulePath . '?staff=' . $managerId, 404);
$directoryFilter = $schedulePath . '?q=PORTAL-1&department=' . $deptId;
$search = $hr->request($directoryFilter, 200);
expect($search->contains('Portal Test Staff') && !$search->contains('Second Portal Staff'), 'schedule filter by staff ID and department');

$assignment = ['csrf' => $scheduleCsrf, 'staff' => [$owner], 'days' => [1, 3, 5],
    'effective_from' => $start, 'effective_to' => $end];
$hr->request($schedulePath, 422, array_replace($assignment, ['days' => [1, 1, 5]]));
$hr->request($schedulePath, 422, array_replace($assignment, ['days' => [1, 5]]));
$hr->request($schedulePath, 422, array_replace($assignment, ['days' => [1, 5], 'exception' => '1', 'reason' => "\u{00A0}"]));
$hr->request($schedulePath, 422, array_replace($assignment, ['effective_from' => (new DateTimeImmutable('today'))->format('Y-m-d')]));
$hr->request($schedulePath, 422, array_replace($assignment, ['effective_to' => $monday->modify('-1 day')->format('Y-m-d')]));
$hr->request($schedulePath, 422, array_replace($assignment, ['staff' => [$owner, $managerId]]));
$readSchedules = new StaffWorkSchedules($db);
$baseline = $readSchedules->calendar();
$fallback = new WorkScheduleCalendar($baseline);
$defaultCount = 0;
for ($offset = 0; $offset < 7; $offset++) {
    $day = $monday->modify("+$offset days");
    $defaultCount += (int) $fallback->expects($day->format('Y-m-d'), (int) $day->format('N'), []);
}
expect($defaultCount === 3, 'an unassigned workweek has exactly three expectations');
expect($readSchedules->periods([$owner]) === [], 'invalid batch leaves first selected staff unchanged');
$hr->request($schedulePath, 303, array_replace($assignment, ['staff' => [$owner, $otherId]]));
$hr->request($schedulePath, 409, $assignment);
$before = $readSchedules->periods([$owner, $otherId]);
expect($before[$owner][0]['days'] === [1, 3, 5] && $before[$otherId][0]['days'] === [1, 3, 5], 'batch assigns exact days');
$idPath = $schedulePath . '?staff=' . $otherId;
$key = $before[$otherId][0]['assignment_key'];
expect($hr->request($idPath . '&replace=' . $key, 200)->contains('Edit planned schedule'), 'future schedule can be opened for editing');
$hr->request($idPath, 303, array_replace($assignment, ['staff' => [$otherId], 'days' => [2, 4, 6], 'replace' => $key]));
$after = $readSchedules->periods([$otherId])[$otherId];
expect($after[0]['days'] === [2, 4, 6] && $after[1]['status'] === 'cancelled', 'a planned edit keeps the previous version');
expect($hr->request($idPath, 200)->contains('Tuesday, Thursday, Saturday'), 'schedule history displays planned days');

$overlap = $db->prepare("INSERT INTO staff_work_schedules
    (user_id, assignment_key, day_of_week, effective_from, effective_to, created_by) VALUES (?, ?, 2, ?, ?, ?)");
try {
    $overlap->execute([$owner, bin2hex(random_bytes(16)), $start, $end, $managerId]);
    expect(false, 'database must reject overlapping assignment periods');
} catch (PDOException $exception) {
    expect($exception->getCode() === '45000', 'database overlap guard runs');
}

$calendarSummary = new ReportingSummary(new ReportAnalytics($db), OperationalPolicy::DEFAULTS, $readSchedules, new \Dtrms\Models\StaffLeaves($db), new \Dtrms\Models\WorkHolidays($db));
$friday = $monday->modify('+4 days')->format('Y-m-d');
$sunday = $monday->modify('+6 days');
$expected = static function (int $id, string $from, string $to, DateTimeImmutable $asOf) use ($calendarSummary): int {
    $filters = ReportFilters::parse(['from' => $from, 'until' => $to], $asOf);
    return array_sum(array_column($calendarSummary->build('staff', $id, $filters, $asOf)['departments'], 'expected'));
};
expect($expected($owner, $start, $friday, $sunday) === 3, 'weekday report expectations honor the first person schedule');
expect($expected($otherId, $start, $friday, $sunday) === 2, 'same date range differs for another staff member');
$policy = array_replace(OperationalPolicy::DEFAULTS, ['reminders_enabled' => true]);
$saturday = $monday->modify('+5 days')->setTime(19, 0);
$reminders = new ReportReminders($db, new Notifications($db));
$reminders->schedule($saturday, $policy);
$reminders->schedule($saturday, $policy);
$events = $db->prepare('SELECT user_id FROM notification_events WHERE event_key IN (?, ?)');
$keys = array_map(static fn (int $id): string => hash('sha256', $id . ':reminder_missing_report:0:' . $saturday->format('Y-m-d')),
    [$owner, $otherId]);
$events->execute($keys);
expect(array_map('intval', $events->fetchAll(PDO::FETCH_COLUMN)) === [$otherId],
    'Saturday reminder reaches only the scheduled staff member once');

$manage = new StaffScheduleManagement($db, $readSchedules);
$change = array_replace($assignment, ['effective_from' => $monday->modify('+7 days')->format('Y-m-d'),
    'effective_to' => '', 'days' => [2, 4], 'exception' => '1', 'reason' => 'Approved accommodation']);
$manage->save($change, ['id' => $managerId, 'role_code' => 'hr_manager', 'permissions' => ['hr_manage_organization']], $sunday);
expect($expected($owner, $start, $friday, $sunday) === 3, 'future change does not rewrite expectations in the original week');
$periods = $readSchedules->periods([$owner])[$owner];
expect($periods[0]['exception_reason'] === 'Approved accommodation' && $periods[0]['days'] === [2, 4]
    && $periods[1]['effective_to'] === $sunday->format('Y-m-d'), 'authorized exception shortens predecessor without erasing history');
expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE event='staff_work_schedule_saved' AND actor_id=$managerId")->fetchColumn() === 4,
    'each changed staff schedule has an audit event');
echo "PASS: HR schedule permissions, date and day validation, batch atomicity, history, overlap guard, expectations and scheduled reminders\n";
