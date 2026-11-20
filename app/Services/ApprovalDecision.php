<?php
declare(strict_types=1);

namespace Dtrms\Services;

use DomainException;
use PDO;
use PDOException;

final class ApprovalDecision
{
    public const COMMENT_LIMIT = 4000;

    public static function comments(string $decision, string $comments): ?string
    {
        if (!in_array($decision, ['approved', 'returned'], true)) {
            throw new DomainException('Choose an approval decision.', 422);
        }
        $comments = trim($comments);
        if (!mb_check_encoding($comments, 'UTF-8') || mb_strlen($comments, 'UTF-8') > self::COMMENT_LIMIT
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $comments)) {
            throw new DomainException('Enter valid comments up to ' . self::COMMENT_LIMIT . ' characters.', 422);
        }
        if ($decision === 'returned' && preg_match('/[^\p{Z}\s]/u', $comments) !== 1) {
            throw new DomainException('A correction reason is required to return this report.', 422);
        }
        return $comments === '' ? null : $comments;
    }

    public static function record(
        PDO $db,
        int $report,
        int $revision,
        int $reviewer,
        string $stage,
        string $decision,
        ?string $comments,
        string $status
    ): void {
        $insert = $db->prepare('INSERT INTO report_approvals (report_id, revision_number, reviewer_id, stage, decision, comments)
            VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$report, $revision, $reviewer, $stage, $decision, $comments]);
        $update = $db->prepare('UPDATE daily_reports SET status = ?, lock_version = lock_version + 1 WHERE id = ?');
        $update->execute([$status, $report]);
    }

    public static function translateConflict(PDOException $error): never
    {
        if (in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
            throw new DomainException('The report was updated by another request. Reload it before taking action.', 409, $error);
        }
        throw $error;
    }
}
