<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\StaffReports;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\DailyReporting;

final class StaffController
{
    public function __construct(
        private StaffReports $reports,
        private DailyReporting $reporting,
        private BrowserSession $browser,
        private array $workPolicy = []
    ) {
    }

    public function dashboard(array $actor): string
    {
        $staff = (int) $actor['id'];
        $month = new DateTimeImmutable('first day of this month');

        return $this->render($actor, 'dashboard', 'Staff dashboard', [
            'counts' => $this->reports->counts($staff),
            'recent' => $this->reports->recent($staff),
            'month' => $month->format('F Y'),
            'attendance' => $this->reports->attendance(
                $staff, $month->format('Y-m-d'), $month->modify('+1 month')->format('Y-m-d')
            ),
        ]);
    }

    public function history(array $actor): string
    {
        $date = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
        $requested = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $status = array_key_exists($requested, StaffReports::STATUSES) ? $requested : '';
        $page = max(1, (int) filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT));
        $error = null;
        if ($date !== '' && !DailyReporting::validDate($date)) {
            $date = '';
            $error = 'Enter a valid date to search.';
            http_response_code(422);
        }

        return $this->render($actor, 'history', 'My reports', [
            'listing' => $this->reports->listing((int) $actor['id'], $date, $status, $page),
            'date' => $date, 'status' => $status === 'supervisor_approved' ? 'pending_hr' : $status,
            'statuses' => array_diff_key(StaffReports::STATUSES, ['supervisor_approved' => true]), 'error' => $error,
        ]);
    }

    public function details(array $actor): string
    {
        $staff = (int) $actor['id'];
        $id = self::requestedId();
        $report = $id ? $this->reports->find($staff, $id) : null;
        if (!$report) {
            return $this->missing();
        }

        return $this->render($actor, 'report', 'Report details', [
            'report' => $report, 'approvals' => $this->reports->approvals($staff, $id),
            'revisions' => $this->reports->revisions($staff, $id),
            'editable' => in_array($report['status'], DailyReporting::EDITABLE, true),
        ]);
    }

    public function form(array $actor): string
    {
        return $this->reportForm($actor);
    }

    public function save(array $actor): string
    {
        $id = self::requestedId();
        $staff = (int) $actor['id'];
        if (isset($_GET['id']) && (!$id || !$this->reports->find($staff, $id))) {
            return $this->missing();
        }

        $input = [];
        try {
            $action = $_POST['action'] ?? '';
            if (!in_array($action, ['draft', 'submit'], true)) {
                throw new DomainException('Choose to save or submit this report.');
            }
            foreach (['report_date', 'arrival_time', 'work_start_time', 'work_end_time', 'content',
                'achievement', 'challenges', 'declared', 'lock_version'] as $field) {
                $value = $_POST[$field] ?? '';
                if (!is_string($value) || strlen($value) > 16000 || !mb_check_encoding($value, 'UTF-8')) {
                    throw new DomainException('One of the form values is invalid or too long.');
                }
                $input[$field] = trim($value);
            }

            $saved = $this->reporting->save($staff, $id, $input, $action === 'submit', new DateTimeImmutable('today'));
            $_SESSION['staff_notice'] = $action === 'submit' ? 'Report sent to your supervisor.' : 'Report changes saved.';
            return Access::redirect('/staff/reports/view?id=' . $saved);
        } catch (DomainException $error) {
            http_response_code(422);
            return $this->reportForm($actor, $input, $error->getMessage());
        }
    }

    private function reportForm(array $actor, array $input = [], ?string $error = null): string
    {
        $id = self::requestedId();
        $staff = (int) $actor['id'];
        $report = $id ? $this->reports->find($staff, $id) : null;
        if (isset($_GET['id']) && !$report) {
            return $this->missing();
        }
        if ($report && !in_array($report['status'], DailyReporting::EDITABLE, true)) {
            http_response_code(409);
            return $this->render($actor, 'locked', 'Report locked', ['report' => $report]);
        }

        $profile = $this->reports->profile($staff);
        $today = new DateTimeImmutable('today');
        $returned = in_array($report['status'] ?? '', DailyReporting::RETURNED, true);
        $returnNote = null;
        if ($returned) {
            foreach ($this->reports->approvals($staff, $id) as $approval) {
                if ($approval['decision'] === 'returned') {
                    $returnNote = $approval;
                    break;
                }
            }
        }
        $fields = $input + ($report ?? []) + ['report_date' => $today->format('Y-m-d')];
        if ($returned) {
            $fields['report_date'] = $report['report_date'];
        }
        return $this->render($actor, 'form', $returned ? 'Correct returned report' : ($report ? 'Edit draft' : 'New daily report'), [
            'profile' => $profile,
            'profileReady' => !empty($profile['staff_code']) && !empty($profile['department_active']),
            'hasSupervisor' => $this->reports->hasSupervisor($staff),
            'fields' => $fields, 'dateLimit' => DailyReporting::dateLimit($profile, $today),
            'report' => $report, 'returned' => $returned, 'returnNote' => $returnNote,
            'error' => $error, 'textLimit' => DailyReporting::TEXT_LIMIT, 'workPolicy' => $this->workPolicy,
        ]);
    }

    private static function requestedId(): ?int
    {
        return filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    private function render(array $actor, string $view, string $title, array $data = []): string
    {
        $message = $_SESSION['staff_notice'] ?? null;
        unset($_SESSION['staff_notice']);
        return View::render('staff/' . $view, $data + [
            'title' => $title, 'principal' => $actor, 'csrf' => $this->browser->csrf(), 'message' => $message, 'error' => null,
        ]);
    }

    private function missing(): string
    {
        http_response_code(404);
        return View::render('error', ['title' => 'Report unavailable', 'message' => 'This report could not be found.']);
    }
}
