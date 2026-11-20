<?php
declare(strict_types=1);

namespace Dtrms\Services;

final class NotificationText
{
    private const COPY = [
        'report_submitted' => ['Report submitted', 'Your daily report was submitted for supervisor review.', true],
        'report_assigned' => ['Supervisor approval required', 'A daily report needs your supervisor review.', true],
        'report_supervisor_approved' => ['Supervisor approval recorded', 'Your supervisor approved your report and sent it to HR.', true],
        'report_pending_hr' => ['HR approval required', 'A supervisor-approved report is ready for HR review.', true],
        'report_returned_by_supervisor' => ['Daily report returned', 'Your supervisor returned a report for correction.', true],
        'report_hr_approved' => ['Daily report approved', 'HR approved your daily report.', true],
        'report_returned_by_hr' => ['Daily report returned', 'HR returned your daily report for correction.', true],
        'account_created' => ['Your account is ready', 'An institutional reporting account has been created for you. Follow the sign-in instructions from your administrator.', true],
        'password_reset' => ['Password reset activity', 'A password reset was initiated for your account. Contact support if you did not expect this change.', true],
        'password_reset_completed' => ['Password reset completed', 'Your password was reset. Contact support if you did not make this change.', true],
        'password_changed' => ['Password changed', 'Your reporting account password was changed.', false],
        'password_change_required' => ['Password change required', 'You will need to change your password at your next sign-in.', false],
        'account_reactivated' => ['Account reactivated', 'Your institutional reporting account is active again.', false],
        'supervisor_changed' => ['Supervisor assignment updated', 'Your reporting supervisor assignment has changed.', false],
        'reminder_missing_report' => ['Daily report reminder', 'A daily report is due. Check your reporting workspace.', true],
        'reminder_supervisor' => ['Supervisor review reminder', 'Your supervisor queue contains reports awaiting review.', true],
        'reminder_hr' => ['HR review reminder', 'Your HR queue contains reports awaiting final review.', true],
        'weekly_schedule_missing' => ['Choose your work dates', 'A reporting week has no confirmed work schedule. Select three dates before the week begins, or contact HR if it has started.', true],
        'weekly_date_approaching' => ['Selected work date approaching', 'A selected work date is approaching or underway. Check your daily report.', true],
        'weekly_date_passed' => ['Selected work date passed', 'A selected work date has passed without a submitted report.', true],
        'weekly_reports_pending' => ['Weekly reports outstanding', 'Reports remain outstanding before the Sunday 11:59 p.m. deadline.', true],
        'weekly_reports_overdue' => ['Weekly deadline missed', 'Reports remained outstanding when the weekly deadline passed.', true],
    ];

    public static function subject(string $event): string
    {
        return self::COPY[$event][0];
    }

    public static function message(string $event): string
    {
        return self::COPY[$event][1];
    }

    public static function email(string $event): bool
    {
        return self::COPY[$event][2];
    }
}
