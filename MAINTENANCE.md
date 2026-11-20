# DTRMS technical handover

DTRMS runs on PHP 8.2+ and MySQL 8. Keep the public document root at public/. Composer supplies PHPMailer, Dompdf and PhpSpreadsheet. Chart.js is served locally. The application uses plain PHP templates, CSS and browser JavaScript.

## Where to make changes

| Responsibility | Entry points |
| --- | --- |
| Request wiring and access | public/index.php, bootstrap/app.php, routes/web.php and app/Middleware/Access.php. Register method, path, permission, role and CSRF policy centrally. |
| HTTP and presentation | Controllers validate request shape and translate domain results into responses. Views consume typed data conventions and escape text with e(). WorkspaceNavigation supplies display metadata only. app.css and app.js own shared components and behavior. |
| Business rules | Services own multi-step workflow, credentials, queue scheduling, organization changes and transactions. Follow existing patterns before adding another service. |
| Data access | Models and transactional services share a PDO connection via Database. Bind values in SQL and use allowlisted fragments. Keep query predicates and ordering visible near the data access that depends on them. |
| Schema and operations | database/migrations, database/seeds and bin. Use a separate migration identity and never amend applied SQL. Deploy in the order described in the installation guide. |

Use deliberate HTTP responses. Use 403 for forbidden actions or failed CSRF, and 404 when the record is unavailable to the caller. Use 409 for a stale approval form or operational configuration conflict and 422 for invalid form data. Legacy staff draft version conflicts retain their existing validation response. Unexpected failures are recorded by the central error handler with category and location and return a generic client response. Keep secrets and staff report text out of diagnostics.

## Access and workflow invariants

All five roles start with the common portal permissions. Staff record their own reports. Supervisors access their assigned staff. HR Officers start with review access. Final HR decisions and organization-wide exports each have their own grants, initially for HR Managers. HR account management requires a separate grant. System administrators manage technical settings and see operational aggregates.

For a protected write, validate the current principal and permission, CSRF, the object's relationship to the principal and the allowed transition. Keep the service checks that run under locks even if the route checks the same role. Treat sidebar links, badges and buttons as navigation and state hints. A notification can outlive the underlying permission or workflow state. Generate its report link only when the current report is viewable, and verify access again when opening the link.

| Action | State and database effect |
| --- | --- |
| Staff saves a draft or correction | Editable draft/returned state only. Preserve the original reporting date for returned reports. The unique staff/date constraint prevents duplicates. |
| Staff submits or resubmits | Require an active department and supervisor. Set submitted, record a numbered immutable snapshot and preserve the first submission timestamp. |
| Supervisor decides | Lock the staff row and report, recheck assignment and reviewer eligibility, then compare lock_version. Approval advances to pending_hr. Return requires a reason and advances to returned_by_supervisor. |
| HR decides | Lock the staff row and report and recheck live HR grants. Require a supervisor approval on the latest revision. Approval advances to hr_approved. Return with a reason advances to returned_by_hr. |

Do not reuse an earlier revision's approval for a resubmission. Approval rows uniquely identify report, revision and stage, with a foreign key to the reviewed snapshot. Keep application transitions, events, audit records and queue publication in the existing transaction. On failure, preserve the previous state. Follow the existing staff-before-report lock order in related changes. Keep historical legacy states readable. Test concurrent changes and conflict handling if editing any lock, trigger or queue.

## Day-to-day maintenance

1. Start with a disposable MySQL schema. Run migrations and seeds and start the local server using the installation guide. Use dedicated test identities.
2. Run PHP lint, node --check public/assets/app.js, and php tests/authentication.php http://127.0.0.1:38731 --require-excel against a fresh seeded schema. The suite covers routes, role and CSRF gates, reports, approval races, deactivation, deliveries and exports. Node is optional tooling for syntax checking. The deployed app serves PHP and browser JavaScript.
3. For query changes, run php tests/performance.php against a disposable schema, examine EXPLAIN for affected queries and compare record counts, sorting and page boundaries. Indexed recent staff reports use the staff ID and newest-first updated timestamp. Contains searches use bound LIKE values and may scan the scoped rows as staff counts grow.
4. For frontend changes, follow the responsive checks in TESTING_REPORT.md. Check light and dark themes and the affected role's routes, including no-data and validation states. Some foundation view components are intentionally retained for reuse.
5. For release, take and verify a private backup, test the migration on a copy, record the change and rerun access and workflow tests. Keep debug credentials and test data away from production. Maintain notification and backup schedules, alert on missed runs and exercise a separate-schema restore.
6. Investigate changes through audit_logs, login_attempts, scheduled_job_executions and bounded application logs. User and workflow mutations emit audit events. Notification reads are per-user state. CLI setup and help-desk events can have a null actor, so correlate them with the host operator log or ticket. Keep those links during operational handover.

## Maintenance review decisions

- The original placeholder workspace had no route and was retired. Its shared navigation metadata remains in use. The generic dialog-launch JavaScript had no active control. The working confirmation handler remains in use.
- Share predicates where a mismatch has user-visible effects. The HR review and notification lookup now share report eligibility; the inbox batch checks only the report IDs displayed. Preserve old notifications even when their detail link is unavailable.
- Models still have small PDO helpers and local pagination methods. Their projections and authorization scopes differ. Prefer focused consolidation when two callers have the same contract.
- Database grants and triggers are defense in depth. Privileged CLI or database writes can bypass application actor, lock and audit rules. Avoid ad hoc production updates to workflow tables. Use approved migration or recovery procedures.
- Deployment checks remain site-specific, including live DNS, VPN access, TLS, backup storage and a post-restore smoke test.

Related runbooks are [installation](INSTALLATION.md), [schema](DATABASE_DOCUMENTATION.md), [recovery](BACKUP_AND_RECOVERY.md), [security](SECURITY_REPORT.md) and [testing](TESTING_REPORT.md).
