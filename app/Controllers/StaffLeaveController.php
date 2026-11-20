<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DateTimeImmutable;
use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\Organization;
use Dtrms\Models\StaffLeaves;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\DailyReporting;
use Dtrms\Services\LeaveAttachments;
use Dtrms\Services\StaffLeaveManagement;

final class StaffLeaveController
{
    public const PATH = '/hr-manager/leaves';
    private const MODES = [
        'dashboard' => 'Leave dashboard', 'queue' => 'Approval queue', 'current' => 'Currently on leave',
        'upcoming' => 'Upcoming leave', 'history' => 'Leave history', 'calendar' => 'Leave calendar', 'approved' => 'Approved leave',
    ];

    public function __construct(
        private Organization $organization,
        private StaffLeaves $leaves,
        private StaffLeaveManagement $management,
        private LeaveAttachments $attachments,
        private BrowserSession $browser
    ) {
    }

    public function index(array $actor): string
    {
        $mode = $_GET['mode'] ?? 'dashboard';
        $mode = is_string($mode) && isset(self::MODES[$mode]) ? $mode : 'dashboard';
        $today = new DateTimeImmutable('today');
        $date = $_GET['date'] ?? '';
        $search = $_GET['q'] ?? '';
        $month = $_GET['month'] ?? $today->format('Y-m');
        $error = null;
        if (!is_string($date) || ($date !== '' && !DailyReporting::validDate($date))) {
            $date = '';
            $error = 'Choose a valid leave date.';
        }
        if (!is_string($month) || !preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/', $month)) {
            $month = $today->format('Y-m');
            $error = 'Choose a valid calendar month.';
        }
        if ($error !== null) {
            http_response_code(422);
        }
        $month = new DateTimeImmutable($month . '-01');
        $filters = [
            'q' => is_string($search) && strlen($search) <= 400 && mb_check_encoding($search, 'UTF-8') ? trim($search) : '',
            'department' => self::number($_GET['department'] ?? null),
            'date' => $date, 'page' => max(1, self::number($_GET['page'] ?? null)),
        ];
        return $this->render($actor, 'leaves', self::MODES[$mode], [
            'modes' => array_diff_key(self::MODES, ['approved' => true]), 'mode' => $mode, 'filters' => $filters,
            'departmentOptions' => $this->organization->departmentOptions(), 'error' => $error, 'month' => $month,
            'counts' => $mode === 'dashboard' ? $this->leaves->counts($today->format('Y-m-d')) : [],
            'listing' => $mode === 'calendar' ? [] : $this->leaves->listing($mode, $filters, $today->format('Y-m-d')),
            'days' => $mode === 'calendar' ? $this->leaves->calendarCounts($filters, $month) : [],
        ]);
    }

    public function form(array $actor, ?string $error = null, array $values = []): string
    {
        $id = self::number($_GET['id'] ?? null);
        $leave = $id ? $this->leaves->find($id) : null;
        if (isset($_GET['id']) && !$leave) {
            return $this->missing();
        }
        if ($leave && $leave['status'] !== 'pending') {
            http_response_code(409);
            return $this->detail($actor, 'Only pending leave can be edited.');
        }
        return $this->render($actor, 'leave-form', $leave ? 'Edit pending leave' : 'Register leave', [
            'leave' => $leave, 'values' => $values + ($leave ?? []), 'staff' => $leave ? [] : $this->leaves->staffOptions(),
            'error' => $error,
        ]);
    }

    public function save(array $actor): string
    {
        $id = self::number($_GET['id'] ?? null);
        if (isset($_GET['id']) && !$id) {
            return $this->missing();
        }
        try {
            $id = $this->management->save($id, $_POST, $_FILES['attachment'] ?? null, $actor);
            return $this->success($id, 'Leave saved.');
        } catch (DomainException $error) {
            if ($error->getCode() === 404) {
                return $this->missing();
            }
            http_response_code($error->getCode() === 409 ? 409 : 422);
            return $this->form($actor, $error->getMessage(), $_POST);
        }
    }

    public function detail(array $actor, ?string $error = null): string
    {
        $leave = $this->leaves->find(self::number($_GET['id'] ?? null));
        return $leave ? $this->render($actor, 'leave-detail', 'Leave record', ['leave' => $leave, 'error' => $error]) : $this->missing();
    }

    public function decide(array $actor): string
    {
        $id = self::number($_GET['id'] ?? null);
        if (!$id) {
            return $this->missing();
        }
        try {
            $this->management->decide($id, $_POST, $actor);
            return $this->success($id, 'Leave decision recorded.');
        } catch (DomainException $error) {
            if ($error->getCode() === 404) {
                return $this->missing();
            }
            http_response_code($error->getCode() === 409 ? 409 : 422);
            return $this->detail($actor, $error->getMessage());
        }
    }

    public function download(array $actor): string
    {
        $leave = $this->leaves->find(self::number($_GET['id'] ?? null));
        $document = isset($leave['attachment_path']) ? $this->attachments->download($leave['attachment_path']) : null;
        if ($document === null) {
            return $this->missing();
        }
        header('Content-Type: ' . $document['mime']);
        header('Content-Disposition: attachment; filename="' . $document['name'] . '"');
        header('Content-Length: ' . strlen($document['bytes']));
        return $document['bytes'];
    }

    private function render(array $actor, string $view, string $title, array $data): string
    {
        $message = $_SESSION['leave_notice'] ?? null;
        unset($_SESSION['leave_notice']);
        return View::render('hr/' . $view, $data + [
            'title' => $title, 'principal' => $actor, 'csrf' => $this->browser->csrf(),
            'message' => $message, 'error' => null, 'types' => StaffLeaves::TYPES, 'statuses' => StaffLeaves::STATUSES,
        ]);
    }

    private function success(int $id, string $message): string
    {
        $_SESSION['leave_notice'] = $message;
        return Access::redirect(self::PATH . '/view?id=' . $id);
    }

    private function missing(): string
    {
        http_response_code(404);
        return View::render('error', ['title' => 'Leave record unavailable', 'message' => 'This leave record or document could not be found.']);
    }

    private static function number(mixed $value): int
    {
        return (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
}
