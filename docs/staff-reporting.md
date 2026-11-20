# Staff portal and daily reporting

## Enable the staff portal

Back up the database and apply the pending migrations with the setup identity. The upgrade gives existing reports stable legacy reference numbers and preserves any previously recorded submission timestamp.

```powershell
php bin/migrate.php
```

The existing `staff_workspace` permission grants access to the portal. Users need a staff ID and an active department to save a report. They also need an active supervisor assignment to submit it. HR manages these from the [HR portal](hr-management.md).

Extend the web database identity’s grants in addition to those in the installation and HR guides.

| Table | Additional access |
| --- | --- |
| `daily_reports` | `SELECT`, `INSERT`, and `UPDATE` on the report’s editable fields, status, submission timestamps, and lock version. Keep the owner and reference immutable through the web update. |
| `report_revisions` | `SELECT` and `INSERT` |
| `report_approvals` | `SELECT` |
| `department_overnight_schedules` | `SELECT` |

The setup identity needs `ALTER` and `UPDATE` for the migration and backfill. The web identity should have read access to `users.future_reports_through` through its existing user read grant.

## Report lifecycle

Staff can open **Staff dashboard**, **My reports**, or **New daily report**. A partial draft can be saved with a valid date. Submission requires the three times, all text fields, and the declaration. Staff name, staff ID, and department come from the authenticated account. Each reporting date can have one report per staff member.

A submitted report becomes read-only while it awaits supervisor or HR review. A returned report can be corrected and resubmitted with the same reporting date. Saving corrections keeps it in Returned status. Each submission creates an immutable snapshot of the identity, department, times, and work fields in the same transaction as the status change. The report keeps its reference and first submission time. Review decisions remain linked to the revision the reviewer saw. Legacy rows with missing historical fields display what was recorded.

The staff dashboard sums recorded work duration from the latest submitted snapshot per reporting date in the current month. It uses the difference between work start and end. Arrival time and unsubmitted changes are excluded from the duration. The attendance section labels the data as staff-entered.

## Configure exceptions

A future reporting date is rejected unless the account has an explicit date limit in `users.future_reports_through`. An authorized database operator can set the inclusive limit for an individual staff account after institutional approval and clear it by setting it to `NULL`. Staff and HR screens cannot change this field.

Overnight entries are disabled by default. An authorized database operator can configure a department’s overnight schedule with one row in `department_overnight_schedules`. One example window uses `earliest_arrival = '18:00:00'` on the reporting day and `latest_end = '07:00:00'` the following morning. Use the arrival date as the reporting date. If a later field crosses midnight, it must fit within this window. Durations use local clock minutes.

## Test

Run the [integration suite](installation.md#run-the-authentication-suite) on a freshly migrated and seeded disposable database. The suite exercises drafts, validation, duplicate and ownership checks, stale edit protection, submission locking, report history, returns and resubmission, date exceptions, and overnight duration. Supervisor and HR decisions are supplied as fixtures so their comments and status changes can be verified from the staff portal.

```powershell
php tests/authentication.php http://127.0.0.1:8080 --require-excel
```
