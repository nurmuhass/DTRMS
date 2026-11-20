# Supervisor portal and approvals

## Upgrade

Back up the database and apply the pending migrations with the setup identity. The upgrade adds `pending_hr` and `returned_by_supervisor` to the report workflow. Older statuses and approval records are retained. Pending reports without a revision receive a snapshot of the fields already stored.

```powershell
php bin/migrate.php
```

In addition to the privileges in the [staff reporting guide](staff-reporting.md), the web database identity needs `INSERT` on `report_approvals` and `SELECT`, `INSERT`, and column-level `UPDATE` on `notifications.read_at`. It already needs `UPDATE` on `daily_reports.status` and `daily_reports.lock_version`. Approval history needs no web `UPDATE` or `DELETE` privileges. The setup identity needs `ALTER` for the status and index changes, `SELECT` on reports, and `INSERT` on revisions for the backfill.

## Review and approve

Open `/supervisor` as a Supervisor with `supervisor_workspace`. A department HOD can review staff reports when their Supervisor account has the corresponding current assignments. Holding a department HOD designation alone does not grant report access.

- The review queue defaults to pending supervisor reports. Filter it by staff name, reporting date, or status.
- The detail page shows the latest submitted work, staff-entered attendance, prior revisions, and approval comments. Saved corrections become visible to supervisors when staff resubmit.
- Approving a pending submission records the reviewer, decision, comments, and timestamp, and advances the report to `pending_hr`. Active HR Officers and HR Managers with report review access receive in-app notifications.
- Returning a submission requires a correction reason. It advances the report to `returned_by_supervisor` and notifies the staff member. Staff can open the report from the notification and submit a corrected revision.
- The approval history and approved reports pages show your decisions for current assignments. Reassignment changes access to the report. Past decisions stay linked to their original reviewer and submission.

The decision transaction locks the staff and report rows, verifies the assignment and form version, and writes the decision, report status, notifications, and audit entry together. The unique report, revision, and stage constraint protects against duplicate decisions. A stale or repeated decision returns a conflict. A supervisor cannot review their own report.

Recipients can view and mark their own notifications as read at `/notifications`. See the [HR final approval guide](hr-final-approval.md) for the next review stage.

## Test

Use a freshly migrated and seeded disposable schema as described in the [installation guide](installation.md#run-the-authentication-suite). Run:

```powershell
php tests/authentication.php http://127.0.0.1:8080 --require-excel
```

The suite covers assignment scope, search, approval, return, resubmission, stale decisions, concurrent review, reassignment, and recipient-scoped notifications.
