# Staff work schedules

This guide describes the archived fixed-day workflow for pre-rollout reporting dates. For new reporting weeks use [flexible weekly schedules](flexible-weekly-scheduling.md).

Open **HR → Work schedules** to search by staff name or ID, filter by department, and view current and upcoming individual assignments. Open a staff name to see their complete schedule history. To assign the same schedule to several staff, select their checkboxes on the current results page and submit the schedule once. A single invalid selection leaves the whole batch unchanged.

Choose three distinct weekdays, a start date of tomorrow or later, and an optional inclusive end date. Blank means open-ended. Only an HR Manager with organization management permission can save schedules. That role can explicitly authorize a different number of days and must give a reason. Staff can continue to submit reports on other dates. These count as actual submissions but do not satisfy a different date’s expectation.

When a future change supersedes a currently effective assignment, the old assignment ends the day before the change begins. Edit a planned assignment using **Edit planned schedule** in its history. Its previous planned version remains in history as cancelled. Overlapping planned assignments are rejected. Dates outside an individual assignment use the default calendar, including any gap left after moving or ending a planned assignment.

## Reporting and reminders

The migration records the organization’s existing weekday pattern and sets the rollout boundary to the next UTC calendar date. Reporting dates before that boundary keep the recorded organization pattern. After the boundary, staff without an individual assignment use exactly three days, taking the first three days of the recorded organization pattern and filling any remaining slots in Monday-to-Sunday order. Default days are shown in the HR schedules screen. The baseline is read-only to the application and is independent of subsequent organization-wide changes.

Monthly summaries and all exports that use those summaries apply the schedule effective on the reporting date. The reporting deadline determines when today is due. Staff reminders follow scheduled days, including weekends if assigned. Supervisor and HR approval reminders continue to use organization-wide working days. Monthly summaries still use the eligible roster available in DTRMS. Prior department membership and employment changes are not reconstructed from schedule history.

## Deployment and verification

Apply migrations before enabling the new feature. Add SELECT, INSERT and UPDATE on staff_work_schedules for the web identity. With column-level grants, limit UPDATE to effective_to and status. The notification worker needs SELECT on staff_work_schedules. Both identities already read system_settings. The migration account needs TRIGGER on the schema.

Application writes lock each staff account in ascending ID order, validate date ranges and save assigned days plus an audit event in the same transaction. The database rejects an inserted period overlapping a different assigned period. Keep all schedule mutations on the HR service path to preserve locking and the audit trail.

Run the authentication integration suite on a disposable MySQL 8 schema. The schedule cases exercise permissions, CSRF, dates, batch rollback, planned edits, overlapping periods, historical expectations, weekend reminders and per-staff audit entries.
