# DTRMS development architecture

## Request lifecycle

The web server serves the CSS and JavaScript from `public/assets/` and passes application requests to `public/index.php`. Bootstrap registers the project autoloader and error handler, loads optional Composer dependencies and view helpers, and reads configuration. Route definitions map methods and paths to controllers. The router returns 404 for unknown paths and 405 with an Allow header for unsupported methods. GET routes also accept HEAD.

Controllers coordinate use cases and pass presentation data to views. Services own business rules and transactions, models handle data access, and middleware applies authorization and CSRF checks. PHP templates share the main layout and components. Escape dynamic text and HTML attribute values with `e()`. Keep URLs controlled by the application or validate them separately for their context.

## Configuration and errors

The web and CLI entry points share bootstrap and environment configuration. PDO creates connections on demand and uses utf8mb4, exceptions, and native prepared statements. Use placeholders for query values. Restrict dynamically selected SQL identifiers to explicit allowlists.

Uncaught exceptions produce a generic web response or a nonzero CLI exit. The application log records the exception type and source location while omitting messages and arguments that can contain credentials. Restrict log access and configure log rotation in the host environment.

## Database migrations

Name each UTF-8 SQL file `YYYYMMDDHHMMSS_description.sql`, with a unique UTC timestamp and lowercase letters, digits, and underscores in the description. Keep files in version control. The CLI runs files in name order under a MySQL advisory lock scoped to the database. It verifies recorded checksums before running pending files. Add a new migration for every correction.

Use one retry-safe statement per file. MySQL DDL may commit implicitly, so a process interruption can leave the SQL applied before its tracking row is recorded. Review the actual schema before retrying a failed migration and reconcile any partial state through the database administrator's change process. The command runs forward migrations. Recovery uses a reviewed compensating migration or a database restore as appropriate.

## Frontend and integrations

The institutional layout uses the supplied navy, gold, and light background palette. The sidebar collapses on tablet and mobile with JavaScript and stays visible when scripting is disabled. Views include reusable breadcrumbs, cards, tables, field templates, alerts, and native dialogs. Authentication forms validate input and CSRF tokens on the server.

Composer is reserved for supporting libraries. PHPMailer handles SMTP, Dompdf produces printable reports, and PhpSpreadsheet reads and writes workbooks. The version-pinned Chart.js bundle under `public/assets/` keeps analytics charts independent of an external CDN.

## Institutional boundaries

The five roles are Staff, Supervisor, HR Officer, HR Manager and System Administrator. HR manages staff accounts, departments and supervisor assignments. The System Administrator manages technical settings, security, backups and application configuration. Accounts are provisioned internally.

The business workflow is Staff → Supervisor → HR. The schema allocates one report per staff member per reporting date, keeps revisions and their decisions, and allows one open supervisor assignment per staff member. Supervisor access follows the current assignment, and HR decision authority follows the configured role permissions.

## Authentication and permissions

The browser session contains an opaque application token. Its SHA-256 digest is stored with the account and credential version in `user_sessions`. Requests check account activation, the credential version, 30 minutes of inactivity, and an eight-hour absolute lifetime. The PHP session ID and CSRF secret rotate on sign-in. Logout is a CSRF-protected POST. A password change or recovery increments the credential version and revokes previous sessions. Future account management must deactivate users and revoke their sessions and outstanding reset codes in one transaction. Historical report, revision, and approval foreign keys restrict deletion.

Login failures are tracked by normalized email digest and server-observed IP. Eight unsuccessful attempts for an email or thirty for an IP within fifteen minutes cause later attempts to return the same generic failure. Plan retention and cleanup for expired session and reset rows and historical login attempts before production operation.

All five roles receive `portal_access`, `password_change`, and `session_logout`. Workspace routes check both the role and its stored permission on the server.

| Role | Route | Permission |
| --- | --- | --- |
| Staff | `/staff` | `staff_workspace` |
| Supervisor | `/supervisor` | `supervisor_workspace` |
| HR Officer | `/hr-officer` | `hr_review_reports` |
| HR Manager | `/hr-manager` | `hr_manage_organization` |
| System Administrator | `/administration` | `technical_workspace` |

Register protected handlers through the centralized access middleware. Add CSRF middleware to state-changing browser routes. New operational permissions should be introduced alongside the use cases and resource-level authorization they control.

The HR module serializes organizational changes per schema while it validates HOD membership, account activation, and supervisor coverage. Each mutation commits with its audit entry. Existing users retain stable internal keys, so institutional staff IDs can be added or corrected without changing report or approval references. Inserting a submitted report or transitioning a draft to submitted requires an active supervisor assignment. The [HR management guide](hr-management.md) documents the new permissions and dependencies.

The staff portal scopes reads and updates by the authenticated user ID. Staff report writes lock the account and existing report, then check the form version and current status. New submissions append a structured revision and an audit event in the report transaction. The attendance summary reads the latest submitted revision per reported date so saved corrections do not alter the summary before resubmission. See the [staff reporting guide](staff-reporting.md) for date and shift policies.

The supervisor portal uses current assignments for report scope. Supervisor decisions use the same staff-then-report lock order, recheck assignment and version, and commit the decision with its audit entry and notifications. The [supervisor approval guide](supervisor-approval.md) describes the queue and decision states.

HR report review and final decisions use separate permissions from organization management. HR eligibility requires a supervisor approval for the current submission revision. The decision, report state, notification, and audit entry commit together. The database guards HR decisions and final state changes against missing or earlier-revision supervisor approval. See the [HR final approval guide](hr-final-approval.md).

The technical administrator uses a distinct role-protected control panel. The panel shares organizational aggregates with the HR reporting dashboard and uses separate projections for technical logs. Operational configuration and role writes commit with audit entries. Session inactivity and password length are enforced by authentication. See the [system administration guide](system-administration.md) for secret handling and scheduler integration.

Notification events, recipient inbox entries, and email queue rows share the originating business transaction. The scheduler uses a schema-level worker lock, event-period uniqueness, and recorded mail attempts. Delivery uses PHPMailer over TLS. See the [notification guide](notifications.md) for reminder timing and retry behavior.

Reporting filters narrow a scope based on the actor’s role and assignment. The reporting service shares metrics between charts and workbooks, and attendance calculations read submitted snapshots. Organizational detail exports require a dedicated HR permission. The technical export uses operational aggregates. See the [reporting guide](reporting-and-exports.md) for the calculation rules.
