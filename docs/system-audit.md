# DTRMS system audit

## A. Overall status

Audit environment on 20 November 2026 used isolated loopback PHP 8.5.12 and MySQL 8.4.13 with a seeded, disposable database. The workflow used synthetic accounts and a test-only future-report allowance to exercise a complete reporting week without waiting. The weekly close service was called at simulated Monday midnight in Africa/Lagos. Separate schemas were used to rehearse upgrades and restoration.

The automated PHP run passed 2,457 assertions. All five role matrices passed, and the end-to-end journey finished at 100% compliance after an approved leave exemption. The target deployment remains pending its own acceptance checks.

## B. Modules and evidence

| Module | Verification |
| --- | --- |
| Authentication and sessions | Installation, five role route matrices, forced password change, recovery, session rotation/timeout/revocation, CSRF and login throttling |
| HR organization | Department lifecycle, assignment history, account lifecycle, staff search and Excel import validation |
| Daily reporting | Ownership, drafts, date and attendance validation, duplicates, revisions, returned reports and supervisor requirement |
| Supervisor and HR review | Sequential approvals, same-revision proof, permission checks, reassignment, concurrent decisions and transactional rollback |
| Schedules, leave and holidays | Exact workday selection, scheduled locking, audited corrections, leave lifecycle, cross-boundary dates, reviewed overlaps and private attachments |
| Staff Submission Monitoring | Filters, pagination, due and outstanding counts, missing schedule state and filtered export |
| Individual reporting and analytics | Weekly and monthly records, date attribution, attendance, Chart.js page, PDF and Excel scope, spreadsheet text cells and departmental totals |
| Notifications and reminders | In-app notifications, AJAX endpoints, scoped report links, queue deduplication and local SMTP-over-TLS delivery, temporary failure, rejection and uncertain delivery |
| Technical administration | Security grants and operational boundaries, monitoring, settings, log privacy and aggregate downloads |
| Backup and recovery | Application-generated bundle, SHA-256 and byte-length verification, and restoration into a different schema |

## C. Critical workflow and calculations

The 16-case regression compares expected, qualifying submitted, outstanding, percent, exempt days and status in that order. Every listed case matched.

| Case | Expected and observed |
| --- | --- |
| Three submissions | 3, 3, 0, 100%, 0, Complete |
| Two submissions | 3, 2, 1, 66.7%, 0, Incomplete |
| One submission | 3, 1, 2, 33.3%, 0, Incomplete |
| Zero submissions | 3, 0, 3, 0%, 0, Not Submitted |
| Full approved leave | 0, 0, 0, N/A, 3, On Leave |
| Partial approved leave | 2, 2, 0, 100%, 1, Complete |
| Pending or rejected leave | Each 3, 1, 2, 33.3%, 0, Incomplete |
| Historical schedule change across a longer window | 4, 2, 2, 50%, 0, Incomplete |
| Scheduled holiday | 2, 2, 0, 100%, 0, Complete |
| Pending supervisor, pending HR, or returned | Each 3, 1, 2, 33.3%, 0, Incomplete |
| Draft only | 3, 0, 3, 0%, 0, Not Submitted |
| Week crossing two months | 3, 2, 1, 66.7%, 0, Incomplete |
| Deactivation mid-period | 2, 1, 1, 50%, 0, Incomplete |

All 35 three-date combinations were accepted and stored as selected. Invalid combinations were rejected. The flexible-scheduling checks separately exercise Sunday 23:59:59 and Monday 00:00:00 in Africa/Lagos, report-date attribution for cross-month records, exclusion of off-schedule and late reports from compliance, and preservation of previous finalized values after HR corrections. Approval status stays separate from submission compliance.

## D. Fixed issues

| Issue and cause | Change and regression |
| --- | --- |
| HR could view a missing-schedule roster but could neither export the filtered results nor sort by compliance or outstanding count. The monitoring query used a fixed name order and the page had no download route. | Added sorting by name, outstanding count or compliance, with stable ordering. The authorized Excel export uses the same filters and MySQL calculation as the screen. The test reads the Excel workbook and checks roster membership and audit history. |
| Standalone daily PDF/Excel, departmental and other summary workbooks, and the administrative workbook generated files without an audit entry. | Record actor and scope after creating the file and before sending it. Include the report ID or report filters where applicable. Mark these downloads non-cacheable. Regressions check scope and audit entries. |

## E. Database changes and verification

This audit added no migrations or schema changes. Fresh schema creation and repeat migration passed. The live disposable MySQL catalog reported 66 migration records, 29 InnoDB base tables, one scoped audit view, 43 foreign-key relationships and 102 indexes. The holiday table is `work_holidays`.

The upgrade rehearsal ran the previous 61 migrations and seeded data with a valid historical supervisor assignment, report and revision. It then applied the five existing `20261119230001` through `20261119230005` migrations. The historical report and revision survived, the cutover fell on a Monday, and a second migration run found no outstanding steps. This checkout had no connection profile for an existing development instance. The rehearsal inspected the test schemas only.

## F. Security

The suite exercises server-side role checks and object ownership, CSRF on protected writes, password and session controls, sequential approval and duplicate-decision handling, private leave document access, email behavior, and PDF and Excel access restrictions. Report queries bind supplied values with native PDO prepares. The roster sort uses a fixed SQL allowlist. Spreadsheet cells derived from names and other text use an explicit text type. The new roster export requires the HR Manager role, organizational management permission, report export authorization and a valid CSRF token.

An audit coverage defect was remediated in section D. Application logs emitted the intentional notification persistence exceptions exercised by the rollback tests. Those tests assert that both the decision and report status roll back. The runtime records only exception type and source location in those entries.

## G. Test results and performance

The PHP regression command was `php tests/authentication.php http://127.0.0.1:<test-port> --require-excel`. It counts assertions, including HTTP status and redirect checks, and exits on the first failure. The SMTP checks used the bundled `tests/smtp-relay.py` with a local trusted test certificate. PHP syntax checks passed for 174 files and JavaScript syntax checks passed for three files.

The continuous workflow verified three selected dates and no submissions, then one report with both approvals and two outstanding dates. Approving leave for one selected date reduced the expected total to two and the outstanding total to one. The last submission reduced outstanding to zero. The local Monday close wrote history once and produced 100% compliance. HR generated an individual weekly PDF and workbook and a departmental monthly workbook. The suite checked the notification links and associated audit events.


| Check group | Executed | Passed | Failed |
| --- | ---: | ---: | ---: |
| PHP regression assertions | 2,457 | 2,457 | 0 |
| Edge page and viewport combinations | 66 | 66 | 0 |
| Edge mobile sidebar and theme checks | 10 | 10 | 0 |
| Performance workloads | 7 | 7 | 0 |
| Database rehearsals (fresh migration, upgrade, backup/restore) | 3 | 3 | 0 |
| PHP and JavaScript syntax checks | 177 | 177 | 0 |
| Total across these groups | 2,720 | 2,720 | 0 |

Five deployment verification groups are blocked by unavailable target configuration or artifacts. One additional coverage group, an exhaustive browser and accessibility review of all screens and states, has no automated suite. See section H. The zero-failure tally refers to the final run. Earlier end-to-end attempts uncovered test fixture issues with the live password policy and session-token handling, which were corrected before rerunning.

Headless Edge opened 22 authenticated pages across widths of 1,440, 820 and 390 pixels, exercised the mobile sidebar and theme toggles across all five roles, and reported no uncaught page JavaScript exceptions. To repeat the browser sample, start a dedicated Edge profile with loopback remote debugging, provision the five synthetic accounts named in `tests/browser-audit.mjs` with the same test password, supply that password in `DTRMS_BROWSER_PASSWORD`, and run `node tests/browser-audit.mjs http://127.0.0.1:<test-port> <debug-port>`. Keep the database and test browser separate from day-to-day accounts.

The benchmark used 70 staff across seven departments with 4,200 reports, 5,460 approval decisions, and 4,200 notifications over three historical months. Each workload took 20 samples on the test computer. These are database/model timings.

| Workload | Median | P95 |
| --- | ---: | ---: |
| Monthly submission monitoring, second page | 89.77 ms | 111.38 ms |
| Three-month submission monitoring | 248.41 ms | 287.82 ms |
| Three-month analytics | 287.47 ms | 307.10 ms |
| HR dashboard | 33.12 ms | 36.52 ms |
| HR queue by name, second page | 39.39 ms | 46.86 ms |
| Staff directory search, third page | 1.67 ms | 2.74 ms |
| Notification history, page 20 | 19.89 ms | 21.39 ms |

## H. Outstanding issues

- Verify the development and staging schema and the deployment-specific web, migration, worker and backup grants.
- Validate internal DNS/VPN reachability, the organization TLS certificate and protected document roots on the target server.
- Verify delivery and authentication with the organization's SMTP relay, and confirm real scheduler invocations under the deployed service accounts.
- Restore a staging backup to a separate staging database using deployment credentials and exercise a restored application login and sequential approval. The local trial verified the dump and restored data.
- Complete manual browser, keyboard and assistive-technology review of page states outside the automated viewport sample.
- The checkout lacked Git metadata and a Composer lockfile. The audit used preinstalled test libraries. Resolve, review and retain a dependency lock and package audit for the deployment artifact.

## I. Files changed

- `app/Controllers/SubmissionMonitoringController.php`, `app/Models/SubmissionMonitoring.php`, `resources/views/hr/submissions.php` and `routes/web.php` implement the filtered export and sorting.
- `app/Controllers/ReportingController.php` records daily, summary and administrative exports.
- `tests/authentication.php`, `tests/reporting-cases.php` and `tests/system-audit-cases.php` cover validation, export controls and the full workflow. `tests/browser-audit.mjs` exercises responsive browser pages.
- `docs/system-audit.md`, `docs/submission-monitoring.md` and `README.md` describe the outcomes and usage.

## J. Deployment readiness

Use a staging environment before institutional rollout. Configure PHP 8.2 or later with Composer requirements met, MySQL 8, the HTTPS site with only `public/` served, Africa/Lagos timezone, restricted application and worker identities, private leave and backup storage, SMTP, recurring notification/finalization and backup workers, and monitoring. Follow the existing installation and recovery procedures. [Installation](../INSTALLATION.md) · [Backup and recovery](../BACKUP_AND_RECOVERY.md)
