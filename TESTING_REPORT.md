# Testing report

Phase 10 used temporary loopback services and disposable schemas on Windows. The suite ran with PHP 8.5.12, MySQL Community Server 8.4.13, restricted web grants, Composer libraries and a certificate-verified test SMTP relay. No live institutional records were used.

## Executed checks

- PHP syntax passed for 139 application and test files. Composer's audit returned no advisories for the resolved test dependencies.
- Fresh migrations and seed, migration and seed rerun, and legacy upgrade passed. The upgrade preserved its fixture account and submission and backfilled the report reference and revision.
- Five roles signed in with expected access. Cross-role denial, CSRF, session rotation and timeout, deactivation, reset expiry and single use, password change, and session revocation passed. Rate-limited retries stayed logged and stopped extending the lockout.
- Staff drafts, duplicate-day rejection, report ownership, escaped markup, attendance, active assignment and future/overnight policies passed.
- The full sequence passed. Supervisor and HR returns, corrections, more than one revision, assignment changes and preservation of earlier decisions passed. Competing supervisor and HR decision tests each allowed one transition. Duplicate approval, missing supervisor proof and unauthorized records were rejected. Transaction failures preserved report state.
- Staff workbook template and import, rejection of formulas and external relationships, atomic rollback and preservation of staff ID leading zeros passed.
- Technical administrator and HR boundaries, settings, audit and log privacy, monitoring, and branding passed.
- SMTP delivery over TLS, retry, permanent failure and uncertain delivery passed against a local fixture. The credential relay-host check, notification recipient scoping, polling and read actions, reminders, deduplication and job history passed.
- Report filters, monthly totals, attendance and late calculations, PDF and all Excel export modes, export scoping and explicit text cells passed.
- An isolated Apache 2.4.58 virtual host with PHP FastCGI passed HTTPS sign-in-page, host-only Secure cookie, HSTS, frame-denial, cleartext rejection, private-path denial and public-asset checks. The temporary test certificate was explicitly trusted by the test client.

## Backup and recovery

The worker refused a public output directory, used separate limited dump and monitoring accounts, published a private bundle, and recorded the failed and successful runs. Its size and SHA-256 matched the manifest. Retention removed the expired same-schema fixture and preserved an unrelated file. A configured scheduled slot published once and returned already completed on immediate repetition.

MySQL loaded the bundle into a separate empty schema. Source and restored counts matched for 90 users, 4,209 reports, 4,210 revisions, 5,474 approvals, 62 audit events and five triggers. The rehearsal compared counts and trigger presence. A post-restore end-to-end application smoke test is a target deployment acceptance item.

## Performance

The CLI benchmark seeded 70 staff in 7 departments, 4,200 reports and notifications, and 5,460 decisions over 3 complete months. Each path ran once to warm up and 20 times for measurement. Times cover PHP and MySQL model execution over loopback. Rendering, client traffic, TLS and concurrent organizational load are outside these timings.

| Path | Median | p95 |
| --- | ---: | ---: |
| Three-month analytics | 271.83 ms | 283.42 ms |
| HR dashboard | 33.30 ms | 36.54 ms |
| HR queue name search, page 2 | 36.62 ms | 40.69 ms |
| Staff search and pagination, page 3 | 1.45 ms | 1.95 ms |
| Notification history, page 20, with unread count | 13.74 ms | 16.49 ms |

The HR dashboard now derives organizational totals from the department aggregation. Directory page counts skip joins used only for display. Notification history has an index matching user and newest-first ordering. Monthly calculations reuse eligible workday counts for staff who share the same eligible start date.

## Reproduction and deployment scope

Run php tests/authentication.php http://127.0.0.1:38731 --require-excel after configuring a fresh loopback dtrms_test_* schema, seeding it and starting the development server. The fixture optionally reads DTRMS_TEST_SMTP_PORT, DTRMS_TEST_SMTP_CA_FILE and DTRMS_TEST_SMTP_JOURNAL for the local test relay. Run php tests/performance.php in a disposable migrated and seeded dtrms_test_* schema with insert/update rights. Both scripts write test fixtures. See the [test setup](docs/installation.md#run-the-authentication-suite) and [deployment checks](INSTALLATION.md#scheduler-and-readiness).

On the target, verify the approved hostname, certificate, internal network/VPN reachability, firewall, real mail relay, scheduler credentials and timing, filesystem permissions and encrypted off-host backup storage. Rehearse recovery on target infrastructure before go-live.

## Phase 11 frontend checks

Headless Microsoft Edge checked 21 representative authenticated pages across all five roles in both themes at 1440, 820 and 390 CSS pixels. The sign-in page was also checked at all three sizes. Browser checks covered page-level overflow, mobile menu close with Escape, notification loading and positioning, theme persistence and chart recoloring, and opening and cancelling the supervisor approval dialog. A mobile review screenshot was checked visually. The PHP/MySQL workflow and permission regression suite also passed.

## Phase 14 leave checks

On a disposable MySQL 8.4.13 database, the suite passed fresh migration and rerun, HR permission and CSRF checks, private PDF upload and authorized download, disguised upload rejection, pending edits, approval, rejection, cancellation, overlap authorization, calendar counts, and audit events. Full-week and partial-week tests used staff with different schedules and verified preservation of an existing submitted report, deduplicated exemptions, restored expectations after cancellation, and reminder suppression on approved leave. The full integration suite and the 70-staff analytics benchmark passed. The current run used deterministic email transport outcomes.

## Phase 15 submission monitoring

The full regression suite passed on MySQL 8.4.13 and PHP 8.5.12. The monitoring cases exercised the exact reporting deadline, ISO week boundaries, individual schedules, approved leave, department and organization-wide holidays, returned reports and draft exclusion. They also verified HR access restrictions, staff drilldown, and availability of the printable report view. A direct policy check verified that cancelled states are excluded.

The performance fixture covered 70 staff in seven departments, 4,200 reports over three months, individual schedules, approved leave and scoped holidays. For 20 measured runs after warmup, monthly monitoring page two took 88.90 ms median and 92.09 ms p95. The three-month monitoring aggregate took 252.38 ms median and 267.38 ms p95. These are loopback PHP/MySQL model timings.

## Phase 16 individual records

The full regression suite passed on isolated MySQL 8.4.13 and PHP 8.5.12. A cross-year ISO week fixture verified that a December 31 report submitted January 2 appears in December's monthly record. It also covered a January schedule change, approved leave spanning both months, and cancellation restoring due dates. Staff ownership, current supervisor assignment and revocation, HR export permissions, CSRF and successful export audit events were verified.

The suite downloaded all four period formats and reopened the Excel workbooks to check references, approval history, exemptions and safe text cells. Both synthetic PDFs were checked with text extraction and visual review of all pages. The 70-staff performance suite also passed.

## Phase 17 compliance and leave matrix

All 16 requested scenarios passed, along with the full role, leave, reporting and export suite and the 70-staff performance fixture. Mid-period account deactivation now preserves earlier active-day obligations. See the [expected and actual results](docs/compliance-test-report.md).

## Flexible weekly scheduling

The full regression suite passed on MySQL 8.4.13 and PHP 8.5.12, including the 16 historical compliance cases and the 70-staff benchmark. The new HTTP and database cases passed for exactly three dates, duplicate and out-of-week rejection, retrospective locks, HR corrections and affected report audits, distinct missing schedules, exact-date leave and cancellation, on-time and late submissions, Sunday timing in Africa/Lagos, cross-month attribution, scoped access, and the corrected weekly workbook totals. The worker queued and deduplicated the test reminders. This run used deterministic email transport.

A separate previous-release upgrade retained a historical daily report and fixed workday, set the new rollout to Monday, and reran with no pending migrations. PHP lint passed for 161 sources and the application JavaScript passed its syntax check.
