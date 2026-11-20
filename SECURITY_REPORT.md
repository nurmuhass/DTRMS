# Security audit

Phase 10 reviewed the existing PHP routes, models, services, templates, browser code, migrations and operational entry points. Verification used synthetic records and disposable MySQL schemas. The review covers application code and the loopback test configuration. See [QA results](TESTING_REPORT.md) for tests actually run.

## Remediated findings

| Finding | Change and verification |
| --- | --- |
| Rejected login retries contributed to the next rate-limit decision, allowing sustained lockout | Count only processed unsuccessful attempts; preserve blocked attempts for monitoring. The HTTP regression aged the processed events while keeping recent blocked events and verified that a legitimate login succeeded. |
| Workforce workbook validation relied on archive filenames for link detection | Reject unsafe ZIP paths and reject external relationships or malformed relationship XML before spreadsheet parsing. A multipart HTTP regression submitted synthetic relationship metadata and verified rejection with no imported account. |
| Credentials followed the database-configured SMTP host | Authenticated delivery now requires the relay hostname to match SMTP_RELAY_HOST from deployment configuration. The constructor regression accepted the approved relay and rejected a mismatched one before delivery. |

Production HTTPS uses a Secure, HttpOnly, SameSite=Lax session cookie with a host-only prefix and session ID rotation on authentication. The public entry point requires a trusted server-side HTTPS indicator and sets HSTS on secure responses, clickjacking protection, a restrictive content security policy and no-store caching. Applying this change ends sessions that used the previous HTTPS cookie name.

## Review coverage

| Surface | Controls examined |
| --- | --- |
| SQL injection and information disclosure | Bound native PDO parameters, fixed query fragments, typed limits and escaped literal searches. Central error logging omits exception messages and arguments, and the browser receives generic failure text. |
| XSS and file uploads | Escaped templates, text-only notification updates, bounded workbook parsing and GD re-encoding of bounded raster logo uploads. The workflow test verifies markup in staff text stays escaped in HTML. |
| CSRF, sessions and authentication | CSRF on state-changing forms, session regeneration, strict cookies, idle and absolute expiry, active account and credential-version checks, bcrypt via PHP password APIs, digest-only reset codes with expiry and revocation. |
| Authorization, IDOR and approval integrity | Protected routes verify role and permissions server-side. Staff reads and exports apply ownership, supervisors use current assignments, and HR final approval requires the separate decision grant and same-revision supervisor proof. Row locking and uniqueness protect concurrent transitions. System administrators receive operational totals and monitoring. |
| Email and exports | Validated addresses and sender inputs, PHPMailer header handling and verified relay TLS. Dompdf disables remote loading, scripting and PHP. The PDF and Excel routes enforce report scope. Workbook text uses explicit text cell types. |
| Configuration and backups | The install guide serves only public/. Loopback Apache HTTPS checks confirmed denial for configuration, vendor, storage and migration paths. The backup worker rejected a web-served destination. Check the same paths and infrastructure permissions on the target. |

Deploy with a dedicated public document root, trusted TLS, least-privilege identities, protected paths, relay allowlisting and external service monitoring. Monitor failed sign-ins and restrict ingress to the approved network. This audit did not test the organization’s live host, VPN, mail relay, ACLs or disaster-recovery infrastructure. See the [deployment runbook](INSTALLATION.md).
