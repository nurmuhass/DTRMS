# System administrator guide

Use System Administration for branding, working days and hours, the reporting deadline, reminders, session timeout, password policy, SMTP settings and scheduled backup cadence. Keep credentials in host-managed environment settings and coordinate authenticated relay changes with the deployment operator.

Review role grants against institutional duties. HR account management, report review, final decisions and detailed exports use separate permissions. Use login, session and audit views to investigate activity. Use application, mail and job history to diagnose issues, and rotate private logs at the host level. Alert on missing scheduler runs, repeated failures, queued mail and stale backup success timestamps.

For password recovery, verify the requester through an approved identity process and issue a code with php bin/issue-password-reset.php using the verified account email. Deliver the code through an approved confidential channel.

See [deployment](INSTALLATION.md), [technical administration](docs/system-administration.md), [notifications](docs/notifications.md) and [recovery](BACKUP_AND_RECOVERY.md).
