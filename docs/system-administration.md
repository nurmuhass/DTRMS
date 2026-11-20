# System administration

## Upgrade and access

Back up the database and apply the pending migrations with the setup identity.

```powershell
php bin/migrate.php
```

Open `/administration` as a System Administrator with `technical_workspace`. HR accounts use their existing workspaces for organizational accounts, departments, and report decisions. The technical dashboard exposes organization-wide totals without individual report contents, attendance details, or reviewer comments.

Role management edits existing display names and compatible grants. The five role codes and baseline account access rules are fixed. HR grants can be configured only for their corresponding HR roles. An administrator cannot grant their system role access to HR data or change account assignments from this panel. Permission and settings changes are audited. Settings audit entries identify changed fields without recording their values.

## Settings and policies

System settings cover branding, official working days and hours, arrival grace, the reporting deadline, reminders, SMTP delivery details, session inactivity and backup policy. The staff form displays the official reporting policy. Report submission continues to use the existing workflow and authorization rules. The configured inactivity timeout is checked on live sessions. New passwords require at least eight characters when changing, resetting, or provisioning an account. The session absolute limit is eight hours.

The logo accepts raster images and normalizes uploads to PNG. Enable PHP GD for logo uploads. Database credentials remain in deployment configuration. Provision SMTP credentials through the worker environment or secret store. The settings screen and export contain only non-secret SMTP connection and sender fields.

## Scheduling and monitoring

The deployment scheduler runs the [notification worker](notifications.md) for reminders and email. Schedule the [database backup worker](../BACKUP_AND_RECOVERY.md) to apply the configured backup policy and record results for the backup panel.

External maintenance workers can read non-secret operational configuration and record their own runs using the CLI commands below. Supply the scheduled UTC timestamp and the same attempt number when recording completion. A later retry uses a new attempt number.

```powershell
php bin/operational-settings.php
php bin/record-job-run.php maintenance 2026-11-19T01:00:00Z 1 running
php bin/record-job-run.php maintenance 2026-11-19T01:00:00Z 1 succeeded
```

The bundled workers record their own runs. Backup health includes the latest recorded run and last successful result. The connectivity page tests the current application connection. The authenticated panel depends on a working database connection, so monitor full database outages from outside the application.

Monitoring shows bounded application error summaries, email delivery states, scheduled job states, and audit events. It omits raw log messages, SQL credentials, SMTP secrets, message recipient addresses, provider identifiers, job details, audit metadata, login identifier hashes, and session tokens. Suspicious login counts are based on unsuccessful attempts within 15 minutes.

## Database access

In addition to the previous web grants, grant the web identity the operations below. Use column-level grants where listed.

| Table | Additional access |
| --- | --- |
| `system_settings` | `SELECT`, `UPDATE` on `setting_value` and `updated_by` |
| `application_branding` | `SELECT`, `UPDATE` on `logo` and `updated_by` |
| `roles` | `UPDATE` on `name` |
| `role_permissions` | `INSERT`, `DELETE` |
| `audit_logs` | `SELECT` on `id`, `created_at`, `event`, `actor_id`, `object_type`, and `object_id` |
| `email_logs` | `SELECT` on `id`, `created_at`, `status`, `sent_at`, and `updated_at` |
| `scheduled_job_executions` | `SELECT` on `id`, `job_name`, `scheduled_for`, `attempt`, `status`, `started_at`, and `finished_at` |

Use a separate scheduler identity with `SELECT` on `system_settings` for the policy export and `SELECT`, `INSERT`, and `UPDATE` on `scheduled_job_executions` for the run recorder. Backup jobs require their own deployment-managed database and storage access.

## Test

Run the full [integration suite](installation.md#run-the-authentication-suite) on a disposable schema. The administrator cases cover HR separation, role-grant boundaries, live password and session settings, logo upload, audit and log privacy, job recording, and backup status.
