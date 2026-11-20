# Notifications and email reminders

## Upgrade and worker configuration

Apply the pending migrations and install the Composer dependencies. Configure delivery under **System settings**. SMTP host, port, encryption, sender, and the email enable switch are editable there. `starttls` uses explicit STARTTLS. `tls` uses implicit TLS.

```powershell
composer install
php bin/migrate.php
```

Configure the worker environment with `APP_BASE_URL`, an HTTPS origin for DTRMS. Supply `SMTP_USERNAME` and `SMTP_PASSWORD` through a private environment or secret store if the relay requires authentication. Set `SMTP_RELAY_HOST` to the deployment-approved hostname for authenticated SMTP. Use `SMTP_CA_FILE` for an institution-managed CA when needed. The worker uses TLS with peer verification. SMTP protocol debugging is disabled.

Schedule the worker at a regular interval such as every five minutes. It handles the in-app queue, reminder scheduling, and the email queue. A database lock prevents overlapping workers for the same application database.

```powershell
php bin/notifications-worker.php 25
```

Example Cron entry using deployment paths and worker environment:

```cron
*/5 * * * * cd /srv/dtrms && /usr/bin/php bin/notifications-worker.php 25
```

In Windows Task Scheduler, set the program to the supported PHP executable, the arguments to `bin/notifications-worker.php 25`, and **Start in** to the DTRMS project directory. Configure recurrence separately. These commands do not require web traffic.

## Event and reminder behavior

Report submission, reassignment, approval, return, and account actions publish events in the same transaction as the underlying change. Recipient-scoped in-app notices are available immediately. Email-eligible events are queued when delivery is enabled. Password security notices contain no temporary password or recovery code. The existing private process still delivers credentials and codes.

Staff with a staff ID and active department receive a missing-report reminder once per working date, after both the configured reminder start and reporting deadline. A draft does not satisfy the submission requirement. Supervisors and HR decision makers with eligible pending reports receive one reminder per configured interval on working days. Intervals start at the configured first reminder time, and periods use the configured application timezone. The worker uses the current local date.

Email delivery records each attempt. Definite temporary failures retry after 5, 15, and 60 minutes. A permanent rejection ends delivery. If the worker stops after claiming an email or loses the relay response after data transmission, the attempt is recorded as uncertain and requires operator review. Retried emails keep the same Message-ID. A recipient address change or deactivation skips a queued email. Disabling delivery pauses the email queue and stops new email queue entries.

The bell and inbox use recipient-scoped AJAX endpoints. Read operations require CSRF. Report links lead to the relevant permission-checked portal. A historical link can stop being available after reassignment or a change in workflow status.

## Database grants

Extend the web identity's existing grants:

| Table | Additional access |
| --- | --- |
| `notification_events` | `SELECT`, `INSERT`, `UPDATE` on `id` and `delivered_at` |
| `notifications` | `UPDATE` on `notification_event_id`, in addition to the previous inbox grants |
| `email_queue` | `INSERT` |

The helpdesk identity that issues reset codes also needs the publisher grants above, `INSERT` and column-level `UPDATE` on `notifications.notification_event_id`, and configuration read access. Give the scheduler its own identity, with read access to operational settings, account eligibility data, reports, revisions, approvals, and supervisor assignments. Give it publisher access, read and status-update access to the email queue, `INSERT` on email logs, and read/write access to scheduled run records. Access to password hashes, reset tokens, and session credentials is unnecessary for the worker.

The web dashboard shows email failure counts and scheduled runs. Raw SMTP replies, credentials, message bodies, and recovery codes stay out of the dashboard and failed-attempt diagnostics. Past queued attempts can be retained according to the institution's deployment policy.

## Tests

The main integration suite tests event generation, recipient scope, deduplication, deadline and interval behavior, job recording, and retry outcomes. For transport integration, `tests/smtp-relay.py` accepts synthetic `example.test` addresses on loopback. Run it with a temporary test certificate/key, a port-file path, and a journal path. Set `DTRMS_TEST_SMTP_PORT`, `DTRMS_TEST_SMTP_CA_FILE`, and `DTRMS_TEST_SMTP_JOURNAL` when running the integration suite to exercise actual PHPMailer delivery over TLS.

The mail transport uses PHPMailer’s SMTP support. [PHPMailer documentation](https://github.com/PHPMailer/PHPMailer)
