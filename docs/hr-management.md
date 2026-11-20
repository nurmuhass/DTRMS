# HR management

## Upgrade or install

Back up the database before applying schema changes. From the project directory, run the migrations and seed with the setup identity.

```powershell
composer install
composer check-platform-reqs
php bin/migrate.php
php bin/seed.php
```

Existing installations receive the new HR Manager grants through the migration. Seeding after the migrations is required on a fresh installation. Rerunning the seed restores baseline HR grants, so apply any local permission reductions after seeding. The migrations add a unique, case-insensitive staff ID, department HOD, assignment-history fields, and report-submission guards. Accounts created before this phase can have an unassigned staff ID. Assign their institutional IDs through Edit account before reactivating an inactive legacy account.

The setup identity needs `ALTER` on the affected tables and permission to create triggers on `daily_reports`, in addition to the [installation privileges](installation.md). Keep the trigger definer available with access to the report, assignment, user, and role tables. If a migration is interrupted after an `ALTER TABLE` or trigger creation, have the database administrator inspect the resulting schema and migration record before retrying.

Extend the web identity’s grants for the HR module:

| Table | Additional access |
| --- | --- |
| `departments` | `SELECT`, `INSERT` on code and name, and `UPDATE` on code, name, HOD and activation status |
| `users` | `INSERT` on staff ID, name, email, department, role and password hash, and `UPDATE` on staff ID, name, email, department and activation status |
| `supervisor_assignments` | `SELECT`, `INSERT` on staff, supervisor, previous supervisor, author and reason, and `UPDATE` on the end timestamp |

Use the SQL column names from the migrations when granting column-level access. The web identity already has the authentication, session, and audit permissions listed in the installation guide.

## Manage the organization

If the institution has no HR Manager account, an authorized setup operator can provision one after the first administrator has been installed. The command displays a temporary password once. Keep it out of logs and shared transcripts, and deliver it privately.

```powershell
php bin/provision-hr-manager.php 'HR-001' 'HR Manager name' 'hr-manager@example.test'
```

Sign in with an active HR Manager account and open `/hr-manager`. The module requires `hr_manage_organization`. Creating or maintaining HR Officer accounts also requires `hr_manage_officers`. The initial grants give both to HR Managers. Staff and supervisors are managed in the same directory. System Administrator and HR Manager accounts are outside this directory's maintenance scope.

- Create departments before creating organizational accounts. A department HOD must be an active member of that department. Clear or replace the HOD before moving or deactivating that account. Move or deactivate active members before deactivating their department.
- Staff IDs accept 1 to 40 ASCII letters, digits, periods, underscores, slashes, or hyphens and are stored in uppercase. Staff IDs and email addresses are unique across accounts.
- Account creation and HR password reset display a generated temporary password once. Deliver it through an approved private channel. Reset, forced password change, and account deactivation end current sessions and invalidate outstanding recovery codes.
- Assign each staff member an active supervisor. A supervisor can cover multiple staff members. Reassign current staff before deactivating a supervisor. Reassignment appends a history record and leaves previously recorded reviewers in place. The database permits drafts before assignment and guards the transition to submitted.
- The audit log records the actor and account or department affected by changes. Passwords are never included in audit metadata.

## Import staff

Download the Excel template from **Import staff**. Keep the `Staff` worksheet and the four headers `staff_id`, `name`, `email`, and `department_code`. Use plain-text cells, including staff IDs with leading zeros. The workbook can contain up to 50 staff rows and be up to 1 MB. Department codes must reference active departments. The importer creates Staff accounts and rolls back the entire workbook on a validation or duplicate error. After importing, issue each person a temporary password from **Account access**.

## Run the tests

Follow the [integration suite setup](installation.md#run-the-authentication-suite) using a fresh disposable schema and installed Composer dependencies. Run the suite with Excel required:

```powershell
php tests/authentication.php http://127.0.0.1:8080 --require-excel
```

The suite covers the five roles, HR permission and CSRF denials, department and account changes, audit entries, assignment history, submission guards, and the Excel download and multipart import.
