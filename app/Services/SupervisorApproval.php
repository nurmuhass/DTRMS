<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;
use Dtrms\Core\Database;
use Dtrms\Models\Notifications;
use Dtrms\Models\StaffReports;
use Dtrms\Models\SupervisorReports;
use Dtrms\Models\UserAccount;
use PDO;
use PDOException;

final class SupervisorApproval
{
    public function __construct(
        private PDO $db,
        private SupervisorReports $reports,
        private StaffReports $staffReports,
        private Notifications $notifications
    ) {
    }

    public function decide(int $actor, int $report, string $version, string $decision, string $comments): void
    {
        $scope = $this->reports->find($actor, $report);
        if (!$scope) {
            throw new DomainException('This report is unavailable.', 404);
        }
        $comments = ApprovalDecision::comments($decision, $comments);

        try {
            Database::transaction($this->db, function () use ($actor, $report, $version, $decision, $comments, $scope): void {
                $staff = (int) $scope['staff_id'];
                $current = $this->reports->lock($report, $staff, $actor);
                if (!$current) {
                    throw new DomainException('This report is unavailable.', 404);
                }
                if ($current['status'] !== 'submitted' || (string) $current['lock_version'] !== $version) {
                    throw new DomainException('This report changed or is no longer awaiting supervisor review. Reload it before taking action.', 409);
                }
                $revision = $this->staffReports->latestRevision($report);
                if (!$revision) {
                    throw new DomainException('This report has no submission revision available for review.', 409);
                }

                $approve = $decision === 'approved';
                $status = $approve ? 'pending_hr' : 'returned_by_supervisor';
                ApprovalDecision::record($this->db, $report, $revision, $actor, 'supervisor', $decision, $comments, $status);
                $this->notifications->supervisorDecision($staff, $report, $revision, $approve);

                (new UserAccount($this->db))->audit(
                    $approve ? 'supervisor_report_approved' : 'supervisor_report_returned',
                    $actor, $staff, 'daily_report', $report, ['revision' => $revision, 'status' => $status]
                );
            });
        } catch (PDOException $error) {
            ApprovalDecision::translateConflict($error);
        }
    }
}
