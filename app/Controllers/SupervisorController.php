<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use DomainException;
use Dtrms\Core\View;
use Dtrms\Middleware\Access;
use Dtrms\Models\StaffReports;
use Dtrms\Models\SupervisorReports;
use Dtrms\Services\BrowserSession;
use Dtrms\Services\ApprovalDecision;
use Dtrms\Services\DailyReporting;
use Dtrms\Services\SupervisorApproval;

final class SupervisorController
{
    public function __construct(
        private SupervisorReports $reports,
        private StaffReports $staffReports,
        private SupervisorApproval $approval,
        private BrowserSession $browser
    ) {
    }

    public function dashboard(array $actor): string
    {
        $id = (int) $actor['id'];
        return $this->view($actor, 'dashboard', 'Supervisor dashboard', [
            'counts' => $this->reports->counts($id),
            'recent' => $this->reports->decisions($id, '', '', '', 1, 5)['rows'],
        ]);
    }

    public function queue(array $actor): string
    {
        $status = self::text('status', 'submitted');
        $statuses = array_diff_key(StaffReports::STATUSES, ['draft' => true, 'supervisor_approved' => true]);
        $status = isset($statuses[$status]) ? $status : '';
        [$name, $date, $page, $error] = $this->filters();
        return $this->view($actor, 'queue', 'Report review queue', [
            'listing' => $this->reports->listing((int) $actor['id'], $name, $date, $status, $page),
            'name' => $name, 'date' => $date, 'status' => $status, 'statuses' => $statuses, 'error' => $error,
        ]);
    }

    public function history(array $actor): string
    {
        return $this->decisionList($actor, false);
    }

    public function approved(array $actor): string
    {
        return $this->decisionList($actor, true);
    }

    public function staff(array $actor): string
    {
        $name = self::text('name');
        return $this->view($actor, 'staff', 'Assigned staff', [
            'name' => $name, 'listing' => $this->reports->staff((int) $actor['id'], $name, self::page()),
        ]);
    }

    public function detail(array $actor): string
    {
        return $this->detailWithMessage($actor);
    }

    public function decide(array $actor): string
    {
        $id = self::id();
        $actorId = (int) $actor['id'];
        if (!$id || !$this->reports->find($actorId, $id)) {
            return $this->missing();
        }
        $comments = $_POST['comments'] ?? '';
        $decision = $_POST['decision'] ?? '';
        $version = $_POST['lock_version'] ?? '';
        try {
            if (!is_string($comments) || strlen($comments) > 16000 || !is_string($decision) || !is_string($version)) {
                throw new DomainException('The form contains an invalid value.', 422);
            }
            $this->approval->decide($actorId, $id, $version, $decision, $comments);
            $_SESSION['supervisor_notice'] = $decision === 'approved' ? 'Report approved and sent to HR.' : 'Report returned for correction.';
            return Access::redirect('/supervisor/report?id=' . $id);
        } catch (DomainException $error) {
            if ($error->getCode() === 404) {
                return $this->missing();
            }
            http_response_code($error->getCode() === 409 ? 409 : 422);
            return $this->detailWithMessage($actor, $error->getMessage(), is_string($comments) && strlen($comments) <= 16000 ? $comments : '');
        }
    }

    private function detailWithMessage(array $actor, ?string $error = null, string $comments = ''): string
    {
        $id = self::id();
        $report = $id ? $this->reports->find((int) $actor['id'], $id) : null;
        if (!$report) {
            return $this->missing();
        }
        $staff = (int) $report['staff_id'];
        $revisions = $this->staffReports->revisions($staff, $id);
        $approvals = $this->staffReports->approvals($staff, $id);
        $latest = $revisions[0]['revision_number'] ?? null;
        $canReview = $report['status'] === 'submitted' && $latest !== null;
        foreach ($approvals as $approval) {
            if ($approval['stage'] === 'supervisor' && $approval['revision_number'] === $latest) {
                $canReview = false;
                break;
            }
        }
        return $this->view($actor, 'report', 'Review report', [
            'report' => $report, 'revisions' => $revisions, 'approvals' => $approvals, 'canReview' => $canReview,
            'comments' => $comments, 'commentLimit' => ApprovalDecision::COMMENT_LIMIT, 'error' => $error,
        ]);
    }

    private function decisionList(array $actor, bool $approved): string
    {
        [$name, $date, $page, $error] = $this->filters();
        $decision = $approved ? 'approved' : self::text('decision');
        $decision = in_array($decision, ['approved', 'returned'], true) ? $decision : '';
        return $this->view($actor, 'decisions', $approved ? 'Approved reports' : 'Approval history', [
            'listing' => $this->reports->decisions((int) $actor['id'], $name, $date, $decision, $page),
            'name' => $name, 'date' => $date, 'decision' => $decision, 'approvedOnly' => $approved, 'error' => $error,
        ]);
    }

    private function filters(): array
    {
        $date = self::text('date');
        if ($date !== '' && !DailyReporting::validDate($date)) {
            http_response_code(422);
            return [self::text('name'), '', self::page(), 'Enter a valid reporting date.'];
        }
        return [self::text('name'), $date, self::page(), null];
    }

    private function view(array $actor, string $view, string $title, array $data): string
    {
        $message = $_SESSION['supervisor_notice'] ?? null;
        unset($_SESSION['supervisor_notice']);
        return View::render('supervisor/' . $view, $data + [
            'title' => $title, 'principal' => $actor, 'csrf' => $this->browser->csrf(), 'message' => $message, 'error' => null,
        ]);
    }

    private function missing(): string
    {
        http_response_code(404);
        return View::render('error', ['title' => 'Report unavailable', 'message' => 'This report could not be found.']);
    }

    private static function id(): int
    {
        return (int) filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private static function page(): int
    {
        return max(1, (int) filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT));
    }

    private static function text(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) && strlen($value) <= 400 && mb_check_encoding($value, 'UTF-8') ? trim($value) : $default;
    }
}
