<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\HrReviews;
use Dtrms\Models\StaffReports;
use Dtrms\Services\ApprovalDecision;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\DailyReporting;
use Dtrms\Services\HrApproval;

final class HrReviewController
{
    public function __construct(
        private HrReviews $reviews,
        private StaffReports $staffReports,
        private HrApproval $approval,
        private BrowserSession $browser
    ) {
    }

    public function dashboard(array $actor): string
    {
        return $this->view($actor, 'dashboard', 'HR reporting dashboard', $this->reviews->dashboard());
    }

    public function queue(array $actor): string
    {
        return $this->listing($actor, 'queue', 'HR approval queue', '/hr/reports');
    }

    public function approved(array $actor): string
    {
        return $this->listing($actor, 'approved', 'HR-approved reports', '/hr/reports/approved');
    }

    public function returned(array $actor): string
    {
        return $this->listing($actor, 'returned', 'Reports returned by HR', '/hr/reports/returned');
    }

    private function listing(array $actor, string $mode, string $title, string $path): string
    {
        $name = $_GET['name'] ?? '';
        $name = is_string($name) && strlen($name) <= 400 && mb_check_encoding($name, 'UTF-8') ? trim($name) : '';
        $date = $_GET['date'] ?? '';
        $error = null;
        if (!is_string($date) || ($date !== '' && !DailyReporting::validDate($date))) {
            $date = '';
            $error = 'Enter a valid reporting date.';
            http_response_code(422);
        }
        $page = max(1, (int) filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT));
        return $this->view($actor, 'list', $title, [
            'listing' => $this->reviews->listing($mode, $name, $date, $page),
            'name' => $name, 'date' => $date, 'path' => $path, 'mode' => $mode, 'error' => $error,
        ]);
    }

    public function detail(array $actor, ?string $error = null, string $comments = ''): string
    {
        $report = $this->reviews->find(self::id());
        if (!$report) {
            return $this->unavailable(404, 'This report is unavailable for HR review.');
        }

        $staff = (int) $report['staff_id'];
        $reportId = (int) $report['id'];
        return $this->view($actor, 'report', 'HR report review', [
            'report' => $report, 'revisions' => $this->staffReports->revisions($staff, $reportId),
            'approvals' => $this->staffReports->approvals($staff, $reportId),
            'canDecide' => Access::permits($actor, HrReviews::DECIDE, HrReviews::ROLES)
                && (int) $actor['id'] !== $staff && $this->reviews->awaitingRevision($reportId) > 0,
            'comments' => $comments, 'commentLimit' => ApprovalDecision::COMMENT_LIMIT, 'error' => $error,
        ]);
    }

    public function decide(array $actor): string
    {
        $id = self::id();
        if (!$id || !Access::permits($actor, HrReviews::READ, HrReviews::ROLES)) {
            return $this->unavailable($id ? 403 : 404, $id ? 'You do not have permission to review reports.' : 'Report unavailable.');
        }
        $comments = $_POST['comments'] ?? '';
        $decision = $_POST['decision'] ?? '';
        $version = $_POST['lock_version'] ?? '';
        try {
            if (!is_string($comments) || strlen($comments) > 16000 || !is_string($decision) || !is_string($version)) {
                throw new DomainException('The form contains an invalid value.', 422);
            }
            $this->approval->decide((int) $actor['id'], $id, $version, $decision, $comments);
            $_SESSION['hr_review_notice'] = $decision === 'approved' ? 'Final approval recorded.' : 'Report returned for correction.';
            return Access::redirect('/hr/reports/view?id=' . $id);
        } catch (DomainException $error) {
            $status = (int) $error->getCode();
            if (in_array($status, [403, 404], true)) {
                return $this->unavailable($status, $error->getMessage());
            }
            http_response_code($status === 409 ? 409 : 422);
            return $this->detail($actor, $error->getMessage(),
                is_string($comments) && strlen($comments) <= 16000 && mb_check_encoding($comments, 'UTF-8') ? $comments : '');
        }
    }

    private function view(array $actor, string $view, string $title, array $data): string
    {
        $message = $_SESSION['hr_review_notice'] ?? null;
        unset($_SESSION['hr_review_notice']);
        return View::render('hr-review/' . $view, $data + [
            'title' => $title, 'principal' => $actor, 'csrf' => $this->browser->csrf(), 'message' => $message, 'error' => null,
        ]);
    }

    private function unavailable(int $status, string $message): string
    {
        http_response_code($status);
        return View::render('error', ['title' => $status === 403 ? 'Access denied' : 'Report unavailable', 'message' => $message]);
    }

    private static function id(): int
    {
        return (int) filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
}
