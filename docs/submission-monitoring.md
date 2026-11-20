# Staff Submission Monitoring

For post-rollout periods, use the [flexible weekly calculation and Sunday cutoff](flexible-weekly-scheduling.md#compliance-and-leave). The historical fixed-day conventions below apply before the rollout boundary.

Open **HR → Submission monitoring**. By default the page shows the current ISO week (Monday through Sunday). Switch the reporting period to Month to see a calendar month. Search by staff name or ID and filter by department, current assigned supervisor, submission status or approved leave. The tiles reflect those filters. Open a staff member for twelve weeks of weekly history, six months of monthly history, selected-period daily submissions, attendance, approval decisions and recent leave records.

HR Managers need the organization management grant. Opening the printable daily report also requires the HR review grant. Downloading its existing PDF additionally requires the export grant. Attendance is staff-entered, and approval status does not determine whether the report was submitted.

## Calculation rules

A due day must belong to the selected period, be on the effective individual schedule or default calendar, come on or after the staff member's account start date, and have reached its local reporting deadline. Approved leave and applicable holidays exempt scheduled dates. A single date is exempt once even when multiple rules apply to it.

The Submitted column shows all genuine submissions in the period. The accompanying due count is the number that match eligible due dates. A submitted report counts while awaiting either approval and remains visible if returned for correction. Draft and cancellation states are excluded. An early report remains visible and satisfies its own date after that date becomes due. An off-schedule or exempt-day report stays in history and actual totals.

Outstanding is the larger of zero and expected minus submitted for due dates. Compliance is submitted for due dates divided by expected, multiplied by 100. A dash means no submissions were due. Final HR approvals and returned reports appear separately.

| Status | Meaning |
| --- | --- |
| Complete | Every eligible due date was satisfied and no eligible deadline remains in the period. |
| In Progress | Some eligible deadlines remain and at least one day has reached its deadline. Any outstanding count covers already-due dates. |
| Incomplete | No eligible deadline remains, at least one due date was satisfied and at least one remains outstanding. |
| Not Submitted | No eligible deadline remains and none of the due dates were satisfied. |
| On Leave | No due dates are outstanding and an approved leave covers today in the selected period, or approved leave exempted all schedule obligations in the period. |
| Not Yet Due | No valid deadline has been reached and no leave status takes precedence. |

The zero-submissions tile counts staff with at least one due date and zero actual submissions. The leave tile and filter count any approved leave in the selected period. Approved leave days are calendar days covered by approved leave, including non-working days. The denominator excludes only scheduled days that the leave covers. The roster uses current department and supervisor assignments. Historical department membership is not reconstructed.

Account activity is evaluated at the scheduled date's local deadline. A deactivated staff member can remain visible in a historical period when they had an obligation, submission or approved leave. The active staff tile counts current active accounts. Earlier active-day obligations stay in historical totals and reactivation does not change previously inactive dates.

## Non-working holidays

Open **Non-working holidays** from the monitoring page. Add a dated organization-wide or department-scoped holiday. Removing one restores the associated expectations unless a different exemption still applies. Additions and removals are audited. Holidays also apply to the existing monthly summaries and missing-report reminders. Messages already sent remain in delivery history.

## Deployment and maintenance

Apply the migration before using monitoring. Grant the web identity SELECT, INSERT and DELETE on work_holidays and the notification worker SELECT. Use the existing schedule, leave and reporting grants for the other sources. The application calendar is passed to a MySQL 8 aggregate with the UTC boundary of each local day. The query produces totals and a page of at most 20 staff rows. It uses the unique staff/date report index and the staff/date interval indexes on schedules and leave.

Grant the web identity SELECT on user_account_status_history. The user-update trigger records future transitions. The upgrade backfills recorded account status audit events. For a legacy inactive account with no status audit, the upgrade uses the account's last update time as an approximation. The migration account needs TRIGGER and access to the history table. See the [executed compliance matrix](compliance-test-report.md).

The regression suite covers cutoff boundaries, an ISO year change, different schedules, approved leave, holiday scope, returned reports, approval separation, permission denial and drilldowns. The performance fixture uses 70 staff and several months of submissions and explicitly measures the monitoring aggregate.

## Filtered Excel roster

Choose Schedule Not Submitted and apply the filters to list staff with missing schedules. Sort the roster by staff name, outstanding count or compliance. HR Managers with report export permission can download the full filtered roster across all pages. The workbook contains selected dates, approved leave exemptions, submission counts, compliance and approval totals. Each successful download records the selected period, filters and row count in the audit log. Exports above 2,000 rows require narrower filters.
