<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;
use Dtrms\Core\Database;
use Dtrms\Models\HrReviews;
use Dtrms\Models\Notifications;
use Dtrms\Models\UserAccount;
use PDO;
use PDOException;

final class HrApproval
{
    public function __construct(private PDO $db, private HrReviews $reviews, private Notifications $notifications)
    {
    }

    public function decide(int $actor, int $report, string $version, string $decision, string $comments): void
    {
        $known = $this->reviews->find($report);
        if (!$known) {
            throw new DomainException('This report is unavailable for HR review.', 404);
        }
        $comments = ApprovalDecision::comments($decision, $comments);

        try {
            Database::transaction($this->db, function () use ($actor, $report, $version, $decision, $comments, $known): void {
                $staff = (int) $known['staff_id'];
                $current = $this->reviews->lock($staff, $report);
                if ($actor === $staff || !$this->reviews->canDecide($actor)) {
                    throw new DomainException('You do not have permission to decide this report.', 403);
                }
                if (!$current || !in_array($current['status'], HrReviews::PENDING, true)
                    || (string) $current['lock_version'] !== $version) {
                    throw new DomainException('This report changed or is no longer awaiting HR review. Reload it before taking action.', 409);
                }
                $revision = $this->reviews->awaitingRevision($report);
                if (!$revision) {
                    throw new DomainException('The current submission needs a supervisor approval before HR can decide it.', 409);
                }

                $approved = $decision === 'approved';
                $status = $approved ? 'hr_approved' : 'returned_by_hr';
                ApprovalDecision::record($this->db, $report, $revision, $actor, 'hr', $decision, $comments, $status);
                $this->notifications->publish($staff, $approved ? 'report_hr_approved' : 'report_returned_by_hr', $report, (string) $revision);
                (new UserAccount($this->db))->audit(
                    $approved ? 'hr_report_approved' : 'hr_report_returned', $actor, $staff, 'daily_report', $report,
                    ['revision' => $revision, 'status' => $status]
                );
            });
        } catch (PDOException $error) {
            ApprovalDecision::translateConflict($error);
        }
    }
}
