# Database documentation

Use MySQL 8, InnoDB and utf8mb4. DTRMS stores its operational data in this database. SQL migrations define constraints and indexes. Seed files establish the baseline roles and permissions.

| Area | Relations and guarantees |
| --- | --- |
| Access | users, roles, permissions and role_permissions hold identities and grants. user_sessions and password_reset_tokens hold digests. login_attempts stores identifier hashes and blocked-attempt state. |
| Organization | departments, department_overnight_schedules and supervisor_assignments hold organization data. A generated key permits one current assignment per staff member. |
| Reports | daily_reports enforces the unique staff/date pair and reference. report_revisions stores snapshots. report_approvals ties a decision to its original approver and revision. Triggers guard required assignments and final approval sequencing. |
| Operations | notifications, notification_events, email_queue, email_logs, audit_logs, scheduled_job_executions, application_branding and system_settings track delivery, history and operational policy. schema_migrations tracks applied versions. |

Database timestamps use UTC, and reporting dates follow the application timezone. Transactions and row locks protect approval transitions. Foreign keys preserve historical references after staff deactivation.

The staff_work_schedules table retains historical fixed workdays. The staff_weekly_schedules and staff_weekly_schedule_days tables store confirmed flexible selections. The staff_weekly_compliance_history table retains versions of closed-week results. The staff_leaves table stores inclusive periods, decision state, actor references and an optional private document key. Only approved leave on selected dates exempts a live weekly obligation. See [weekly schema and grants](docs/flexible-weekly-scheduling.md#deployment) and [leave storage](docs/staff-leave.md#deployment).

Apply migrations before the matching code release. MySQL DDL uses implicit commits. Following a failure, verify which statements applied and rehearse recovery before retrying. Use an account with migration privileges for setup and a separate runtime account without DDL or GRANT privileges.

Start with grants in [installation](docs/installation.md) and layer [organization](docs/hr-management.md), [staff](docs/staff-reporting.md), [supervisor](docs/supervisor-approval.md), [HR review](docs/hr-final-approval.md), [technical monitoring](docs/system-administration.md), [workers](docs/notifications.md) and [exports](docs/reporting-and-exports.md). Add UPDATE on login_attempts.blocked if using column-level grants. Separate backup identities are documented in [backup and recovery](BACKUP_AND_RECOVERY.md).
