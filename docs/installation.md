# DTRMS installation and account recovery

## Prepare the database

Use PHP 8.2+ with PDO MySQL and mbstring, and a maintained MySQL 8 server. The integration suite also uses PHP's curl extension. Install supporting packages with Composer and run `composer check-platform-reqs`.

Create an empty schema as a MySQL administrator.

```sql
CREATE DATABASE dtrms CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

Use separate database credentials for setup, the web application, and password recovery. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the appropriate process environment. For local use, copy `.env.example` to `.env`. Process settings take precedence over the file. Protect both the configuration and the PHP session and application log directories with OS permissions.

| Identity | Required schema access |
| --- | --- |
| Setup | `CREATE`, `REFERENCES`, `SELECT`, `INSERT`, and `UPDATE` on the DTRMS schema for the initial migrations, seed, and installer |
| Web | `SELECT` on `users`, `roles`, `permissions`, and `role_permissions`. `UPDATE` on `users.last_login_at`, `password_hash`, `must_change_password`, `credential_version`, and `password_changed_at`. `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on `user_sessions`. `SELECT`, `INSERT`, and `UPDATE` on `login_attempts`. `SELECT` and `DELETE` on `password_reset_tokens`. `INSERT` on `audit_logs`. |
| Recovery operator | `SELECT` on `users` and column-level `UPDATE` on `users.last_login_at` for its account locking read. `SELECT`, `INSERT`, and `DELETE` on `password_reset_tokens`, and `INSERT` on `audit_logs`. |

The recovery command locks the user row while replacing a code. MySQL requires an additional privilege for `SELECT ... FOR UPDATE`, in addition to `SELECT`. [MySQL locking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html)

Do not give the web identity write access to roles, permissions, installation settings, or user activation flags. Remove setup credentials from the web server after provisioning.

## Initialize

Run these commands with the setup identity from the project directory.

```powershell
php bin/migrate.php
php bin/seed.php
```

For the HR module, apply the additional [setup and web database grants](hr-management.md#upgrade-or-install).

The seed establishes the initial role grants and installation state. Run it at initial provisioning. A rerun preserves installation state and restores any missing baseline grants.

Create the first administrator from an authorized console. Supply a generated temporary password via stdin. The following example uses a masked PowerShell prompt and passes its value to PHP without placing it in the command arguments or command history. Use an ASCII temporary password with Windows PowerShell, whose native-command input encoding depends on the shell version and settings.

```powershell
$adminSecret = Read-Host 'Temporary administrator password' -AsSecureString
try {
    ([System.Net.NetworkCredential]::new('', $adminSecret).Password) |
        php bin/install-admin.php 'Administrator name' 'admin@example.test'
} finally {
    $adminSecret.Dispose()
}
```

Use the institution's actual administrator name and email. The password must contain at least eight characters. The installer creates the account, logs initialization, and updates the database installation flag in one transaction. Subsequent installer calls fail. The temporary password must be changed at first sign-in.

## Run locally

With a local database and the web identity configured, enable the loopback exception for this PowerShell session and start the PHP development server.

```powershell
$env:APP_ENV = 'development'
$env:APP_ALLOW_LOOPBACK_HTTP = '1'
php -S 127.0.0.1:8080 -t public public/index.php
```

Open [DTRMS sign-in](http://127.0.0.1:8080/login). For intranet hosting, serve HTTPS and leave the loopback exception off. If an approved reverse proxy terminates TLS, configure the trusted web server to expose the verified HTTPS state as `HTTPS=on` to PHP. Restrict direct access to the upstream server. Give the PHP process write access to `storage/sessions/` and `storage/logs/`.

## Recover a password

The public recovery page gives every visitor the same instructions. It performs no account lookup. The authorized helpdesk verifies the requester's identity under institutional procedures before a designated operator uses the recovery command. Restrict the recovery database identity and operator shell access to those operators.

```powershell
php bin/issue-password-reset.php 'verified-user@example.test'
```

Deliver the code through the approved private channel. Treat the command's output as a credential and keep it out of logs, shell transcripts, and shared tickets. The user enters it on `/reset-password`. The code expires after 30 minutes, replaces any previously issued code for the account, and can be used once. A successful reset revokes existing sessions. The audit log records issuance and redemption against the account. Use host or database auditing to attribute CLI operations to individual operators.

## Run the authentication suite

Create a disposable schema whose name starts with `dtrms_test_`. Run migrate and seed against it, then start a loopback server with the local environment from above, pointing it at the same disposable schema. From another terminal configured to use that test schema, run:

```powershell
php tests/authentication.php http://127.0.0.1:8080
```

The suite requires a fresh seeded schema with no users. It creates five test accounts and report fixtures. Drop the disposable schema after the run.
