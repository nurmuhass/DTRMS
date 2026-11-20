# Backup and recovery

Schedule php bin/backup.php under a dedicated host identity every five minutes. Configure cadence, local time, weekday and retention in System Administration. The daily or weekly slot is eligible from its scheduled local time until midnight. A successful attempt closes the slot. A failed attempt can be retried while eligible. Use php bin/backup.php --now for a manual backup. Coordinate backups with schema changes because concurrent DDL can invalidate a logical snapshot.

## Private storage

Set BACKUP_DIRECTORY to an existing private absolute directory and BACKUP_WEB_ROOTS to every document root and served alias directory. Use semicolon separators on Windows and colons on Unix. Set BACKUP_MYSQLDUMP to the absolute path to the MySQL 8 mysqldump executable. Set BACKUP_DEFAULTS_FILE to a private option file with a [client] section for the dump user and password. The dump uses DB_HOST, DB_PORT and DB_NAME from the worker configuration. For remote backup connections configure TLS with server identity verification. Store the directory and the option file outside the checkout and all served roots. Use owner-only Unix permissions or restricted Windows NTFS ACLs. Use encrypted storage and replicate approved bundles to protected off-host storage.

Give the dump identity SELECT, SHOW VIEW and TRIGGER on the source schema. The separate PDO identity needs SELECT on system_settings and SELECT, INSERT and UPDATE on scheduled_job_executions. Supply its credentials through the scheduled process environment. [MySQL mysqldump reference](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)

Each published bundle contains dump.sql and manifest.json, which records schema, timestamp, size and SHA-256. Retention removes eligible expired bundles for the current schema. Monitor scheduler failures, the age of the latest successful job and storage capacity. Inspect abandoned staging directories with the worker stopped. Use trusted storage and chain of custody to establish authenticity.

## Restore rehearsal

1. Select a trusted bundle and verify dump.sql length and SHA-256 using sha256sum or Get-FileHash -Algorithm SHA256. Compare the results with manifest.json.
2. Create a new empty, restricted MySQL 8 schema. Keep the application and workers disconnected. Plan for trigger definers and their grants with the DBA. Use a trusted restoration account and a private option file.
3. On Unix, restore using the command below. On Windows, use Start-Process with mysql.exe, equivalent arguments, RedirectStandardInput set to the full dump.sql path, Wait and PassThru. Require exit code zero.

   ~~~sh
   mysql --defaults-file=/private/restore.cnf --database=dtrms_recovery < /private/bundle/dump.sql
   ~~~

4. Compare counts and sampled relationships for reports, revisions, approvals and audit records. Review foreign keys and triggers. Perform a staging login and sequential approval against the restored database before cutover.
5. For service recovery, revoke restored sessions and reset tokens. Reconcile queued mail and in-app delivery against logs to avoid replay. Verify grants, settings, jobs and record the actual recovery time and snapshot timestamp. Plan separate binlog recovery if the organization requires point-in-time recovery. An approved operator must authorize any production cutover.

See [QA results](TESTING_REPORT.md) for the disposable-schema rehearsal.
