# Weekly compliance and leave test report

The executed DTRMS suite used PHP 8.5.12 and MySQL 8.4.13 on disposable loopback services. The matrix used the closed ISO week from April 26 through May 2, 2027 and compared independent expected values with the output of the production monitoring aggregation.

Each result lists **due / submitted / outstanding / compliance / leave-exempt scheduled days / status**. A dash means no submission came due and the percentage is undefined.

| Case | Scenario | Expected | Actual | Result |
| ---: | --- | --- | --- | --- |
| 1 | Three submissions | 3 / 3 / 0 / 100% / 0 / Complete | 3 / 3 / 0 / 100% / 0 / Complete | Pass |
| 2 | Two submissions | 3 / 2 / 1 / 66.7% / 0 / Incomplete | 3 / 2 / 1 / 66.7% / 0 / Incomplete | Pass |
| 3 | One submission | 3 / 1 / 2 / 33.3% / 0 / Incomplete | 3 / 1 / 2 / 33.3% / 0 / Incomplete | Pass |
| 4 | Zero submissions | 3 / 0 / 3 / 0% / 0 / Not Submitted | 3 / 0 / 3 / 0% / 0 / Not Submitted | Pass |
| 5 | Full approved leave | 0 / 0 / 0 / — / 3 / On Leave | 0 / 0 / 0 / — / 3 / On Leave | Pass |
| 6 | Partial approved leave | 2 / 2 / 0 / 100% / 1 / Complete | 2 / 2 / 0 / 100% / 1 / Complete | Pass |
| 7 | Pending leave | 3 / 1 / 2 / 33.3% / 0 / Incomplete | 3 / 1 / 2 / 33.3% / 0 / Incomplete | Pass |
| 8 | Rejected leave | 3 / 1 / 2 / 33.3% / 0 / Incomplete | 3 / 1 / 2 / 33.3% / 0 / Incomplete | Pass |
| 9 | Schedule change | 4 / 2 / 2 / 50% / 0 / Incomplete | 4 / 2 / 2 / 50% / 0 / Incomplete | Pass |
| 10 | Scheduled holiday | 2 / 2 / 0 / 100% / 0 / Complete | 2 / 2 / 0 / 100% / 0 / Complete | Pass |
| 11 | Awaiting supervisor approval | 3 / 1 / 2 / 33.3% / 0 / Incomplete | 3 / 1 / 2 / 33.3% / 0 / Incomplete | Pass |
| 12 | Awaiting HR approval | 3 / 1 / 2 / 33.3% / 0 / Incomplete | 3 / 1 / 2 / 33.3% / 0 / Incomplete | Pass |
| 13 | Returned report | 3 / 1 / 2 / 33.3% / 0 / Incomplete | 3 / 1 / 2 / 33.3% / 0 / Incomplete | Pass |
| 14 | Draft only | 3 / 0 / 3 / 0% / 0 / Not Submitted | 3 / 0 / 3 / 0% / 0 / Not Submitted | Pass |
| 15 | Week spans two months | 3 / 2 / 1 / 66.7% / 0 / Incomplete | 3 / 2 / 1 / 66.7% / 0 / Incomplete | Pass |
| 16 | Deactivated mid-period | 2 / 1 / 1 / 50% / 0 / Incomplete | 2 / 1 / 1 / 50% / 0 / Incomplete | Pass |

## Historical and access checks

- The schedule transition kept earlier dates on their original pattern. The fixture contains four distinct workdays because the change occurred midweek.
- A report submitted in May for an April reporting date counted in April. The cross-month and cross-year weekly fixtures included both sides of their boundaries.
- Deactivation kept obligations from the earlier active days and excluded later deadlines. Reactivation restored future eligibility while preserving the earlier inactive interval. The dashboard still showed the historical staff row and excluded it from active headcount.
- A separate previous-release schema upgrade restored two timestamped transitions from audit events, initialized a legacy inactive account from its last update, and reapplied migrations with no pending work.
- The full suite passed approved-leave, holiday, exact-deadline, returned-report, and role and CSRF regressions. Weekly and monthly PDF and Excel downloads passed, and the workbooks were reopened to verify report dates, approvals, leave exemptions and safe text cells. Staff ownership, supervisor assignment and its revocation, HR grants, and export audits passed.
- The 70-staff performance fixture passed with 4,200 submissions over three months.

Run the matrix through php tests/authentication.php http://127.0.0.1:38747 --require-excel after starting the app against a fresh migrated and seeded dtrms_test_* database. It prints one COMPLIANCE JSON record per scenario with expected and actual results. See the [test setup](installation.md#run-the-authentication-suite).
