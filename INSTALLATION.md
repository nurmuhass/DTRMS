# Internal installation

Deploy with PHP 8.2+, MySQL 8, Composer 2 and Apache 2.4. Verify the PHP and MySQL versions independently on XAMPP. Enable PDO MySQL, mbstring, DOM, Fileinfo, GD, OpenSSL and Zip plus the extensions reported by Composer. Enable curl on QA hosts.

1. Place the checkout outside other websites' document roots. Reserve an internal DNS name, obtain a trusted TLS certificate for it and allow port 443 only from approved network and VPN ranges. Bind colocated MySQL to loopback. For a remote database, restrict ingress to the application host and arrange authenticated encrypted transport.
2. With the target PHP version, run composer install --no-dev --prefer-dist --optimize-autoloader, composer check-platform-reqs --no-dev and composer audit. Keep the reviewed resolved dependency artifact for deployment.
3. Start with .env.example. In production set APP_ENV=production, APP_ALLOW_LOOPBACK_HTTP=0, APP_TIMEZONE and the HTTPS APP_BASE_URL. Supply DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD through a service environment or tightly restricted .env file. Separate the migration, web, notification and backup identities. See the [database document](DATABASE_DOCUMENTATION.md).
4. Create the utf8mb4 schema. Run php bin/migrate.php, then php bin/seed.php for the initial installation. Run php bin/install-admin.php "Initial Administrator" admin@example.test with the password supplied on stdin through a confidential prompt. Provision the HR Manager with php bin/provision-hr-manager.php HR-001 "HR Manager" hr@example.test. Change the temporary passwords at first login. Store credentials outside command history.
5. Make source and dependencies read-only to web and worker identities. Allow the web identity to write storage/sessions and the applicable service identities to write storage/logs. Check restrictive NTFS ACLs or Unix ownership for configuration and private data. Set display_errors=Off, expose_php=Off, log_errors=On, file_uploads=On, upload_max_filesize=6M and post_max_size=8M. Use a private temporary upload directory and bounded PHP memory and execution limits. Configure [private leave document storage](docs/staff-leave.md#deployment) for supporting uploads.
6. Configure SMTP relay, port, encryption and sender in System Administration. Set SMTP_USERNAME and SMTP_PASSWORD only in the worker environment when authentication is needed. Set SMTP_RELAY_HOST to the approved relay hostname for authenticated SMTP. Add SMTP_CA_FILE when a separate trust bundle is required. Restrict outbound traffic to approved services. See the [notification guide](docs/notifications.md).

## Apache TLS example

Replace example host, paths and IP ranges and configure the site's supported PHP handler. Load mod_ssl and mod_rewrite.

~~~apache
<VirtualHost *:443>
    ServerName dtrms.example.test
    DocumentRoot "/srv/dtrms/public"
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCertificateFile "/etc/dtrms/tls/fullchain.pem"
    SSLCertificateKeyFile "/etc/dtrms/tls/private.key"

    <Directory "/srv/dtrms">
        AllowOverride None
        Require all denied
    </Directory>
    <Directory "/srv/dtrms/public">
        AllowOverride None
        Options -Indexes -MultiViews
        Require ip 10.20.0.0/16 10.30.12.0/24
        DirectoryIndex index.php
        RewriteEngine On
        RewriteRule ^assets/(?:app\.css|app\.js|chart\.umd\.min\.js)$ - [END]
        RewriteRule ^index\.php$ - [END]
        RewriteRule ^ index.php [END]
    </Directory>
</VirtualHost>
~~~

Terminate TLS at Apache for this configuration so PHP receives the validated HTTPS indicator. Test the configuration with apachectl configtest or httpd.exe -t before reloading. Explicitly deny the checkout in any legacy parent site if it remains under a wider DocumentRoot. Check all aliases for configuration or storage exposure. [Apache TLS configuration](https://httpd.apache.org/docs/2.4/ssl/ssl_howto.html)

## Scheduler and readiness

Apply the flexible weekly schedule migrations and the associated web and worker grants. Keep the minute-by-minute notification worker enabled so it can lock schedules, finalize results and queue reminders. [Weekly scheduling deployment](docs/flexible-weekly-scheduling.md#deployment)

Set service-specific secrets and absolute paths. Example system crontab entries:

~~~cron
* * * * * dtrms-worker /usr/bin/php /srv/dtrms/bin/notifications-worker.php
*/5 * * * * dtrms-backup /usr/bin/php /srv/dtrms/bin/backup.php
~~~

On Windows, create one repeating Task Scheduler job per worker. Use the selected php.exe as Program, the full script path as Arguments and the checkout as Start in. Run under the corresponding service identity. Disallow overlapping instances and monitor exit codes and missed starts.

Before rollout, check trusted TLS, sign-in and permissions from LAN and VPN. Verify denial from outside approved ranges. Check that neighboring/default virtual hosts cannot serve private files. Test a full approval and export, real SMTP delivery, scheduled backup publication and a recovery rehearsal. For an upgrade, back up first, rehearse migrations on a copy and schedule downtime for schema changes and workers.

See [local setup](docs/installation.md), [backup and recovery](BACKUP_AND_RECOVERY.md) and [QA results](TESTING_REPORT.md).
