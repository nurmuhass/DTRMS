# Flexible weekly scheduling

The rollout starts on the Monday after the migration's UTC date. Earlier dates retain the previous schedule and deadline rules. The old fixed schedule records, daily reports and approvals are retained. The application runs weekly dates and deadlines in APP_TIMEZONE, which defaults to Africa/Lagos.

## Staff and HR workflow

Open **My Weekly Work Schedule** before the reporting week begins. Select any three distinct eligible dates between Monday and Sunday. A draft does not establish obligations. Confirm the selection to establish the schedule. The server enforces the lock from Monday at 00:00, even when the scheduled worker has yet to mark the row locked. A late or missing schedule requires HR action.

Use **HR → Weekly schedules** to review selections, correct them, record an unlock or cancel a schedule. Each HR action requires a reason. An HR unlock keeps staff editing blocked on weeks that have started. The audit records previous and new dates and identifies submitted reports on any removed dates. Report records remain unchanged.

The holiday calendar blocks dates from new selections. If a holiday is announced after confirmation, HR can correct the weekly selection with an audit reason. The original schedule is retained in the schedule audit.

## Compliance and leave

The Sunday deadline includes the entire 11:59 p.m. minute in the configured timezone. Before then, full expected and outstanding counts are visible. Only selected dates before today count as already due. The closed week counts any remaining qualifying-date shortfalls as missed. Late and off-schedule reports remain visible in actual submissions. A report satisfies only its own selected date and must have been first submitted by the Sunday deadline. Drafts and cancellations do not count. Returned reports retain submitted status and require correction.

Only approved leave covering an exact selected date exempts that date. Monthly totals use the reporting or selected date within the month, even when the week crosses months. The HR page distinguishes missing schedules from confirmed schedules with missing reports. A missing schedule leaves expectations unset; a confirmed schedule with zero expectations displays N/A for compliance.

The worker appends a final result after the deadline. HR schedule changes and approved leave decisions affecting a closed week recalculate the result and keep prior versions. Open a staff member in Submission Monitoring to view the final-result history. Individual period exports include selected dates, adjusted expectations, approvals and exemptions. Monthly departmental figures use the same flexible-date calculation.

## Deployment

Apply all five 202611192300 migrations. These create weekly schedules, their selected dates, versioned final results, an activation setting and a schedule-scoped audit view. The audit view's definer requires persistent SELECT access to its projected audit columns. The web identity needs SELECT, INSERT and UPDATE on staff_weekly_schedules, SELECT/INSERT/DELETE on staff_weekly_schedule_days, SELECT/INSERT on staff_weekly_compliance_history and SELECT on weekly_schedule_audit. The worker needs SELECT on all three tables, UPDATE on the schedule header, SELECT/INSERT on result history, and the existing audit and notification privileges. User row locking requires the existing account locking privileges.

Keep bin/notifications-worker.php running each minute through Cron or Windows Task Scheduler. It locks started schedules, records final results and, when reminders are enabled, queues missing-schedule, selected-date and weekly-deadline reminders. Approval reminders, mail retry, deduplication and delivery auditing use the existing notification engine.
