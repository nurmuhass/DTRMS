# HR final approval

## Upgrade and permissions

Back up the database and apply the pending migrations with an identity that can alter reports, write permission grants, and create triggers on `daily_reports` and `report_approvals`.

```powershell
php bin/migrate.php
```

On a fresh installation, also run `php bin/seed.php`. The migrations grant review permissions to existing roles. Rerunning the seed restores the baseline grants.

| Role | Review access | Final approval and return | Organization management |
| --- | --- | --- | --- |
| HR Officer | `hr_review_reports` | No default grant | No default grant |
| HR Manager | `hr_review_reports` | `hr_decide_reports` | `hr_manage_organization` |

Both review and decision permissions are required to make a final decision. Organization management grants do not confer report access or decision rights. Authorized database operators can administer role permissions. The two HR roles have organizational report visibility by default.

The existing [supervisor approval grants](supervisor-approval.md) cover the web database operations for this phase. Keep the trigger definer available with access to reports, revisions, and approvals. The triggers enforce the supervisor prerequisite on new HR decisions and final report state changes. Existing historical rows are preserved.

## Review workflow

Open `/hr` for organizational totals and departmental submission statistics. The organizational total includes drafts. Department submission counts exclude drafts. Pending HR totals count the same eligible reports as the queue. The returned total includes both approval stages. The Returned by HR page lists current HR returns with a recorded HR decision.

The queue includes only reports awaiting HR with a successful supervisor decision on the latest submission and no HR decision for that submission. Details include supervisor identity, approval time, comments, the underlying work and self-reported attendance, and earlier revisions and decisions.

Approve to record final approval or return with a correction reason. The report changes to `hr_approved` or `returned_by_hr`, and the staff member receives an in-app notification. A returned report goes through supervisor approval again after corrections are submitted. Earlier supervisor approvals remain part of the history.

The application locks the staff and report rows, rechecks authorization and form version, and writes the decision, status, notification, and audit event together. The unique report, revision, and stage key blocks duplicate decisions.

## Test

Use a fresh disposable database as described in the [installation guide](installation.md#run-the-authentication-suite).

```powershell
php tests/authentication.php http://127.0.0.1:8080 --require-excel
```

The HR cases cover separate permissions, direct database guards, the supervisor-to-HR sequence, a return and new submission, stale and concurrent decisions, recipient notifications, and transaction rollback.
