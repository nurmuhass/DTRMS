# Reporting, analytics, and exports

## Upgrade and access

Install the Composer dependencies and run the migrations with the setup identity. Fresh installations also run `php bin/seed.php`.

```powershell
composer install
php bin/migrate.php
```

Open **Reporting and analytics** in the sidebar. Chart.js 4.5.1 is served from the application, so the charts work without a public CDN.

| Role | Reporting scope |
| --- | --- |
| Staff | Their own reports and scoped exports |
| Supervisor | Submitted reports for currently assigned staff and scoped exports |
| HR Officer | Organizational analytics with `hr_review_reports`. Exports also require an explicit `hr_export_reports` grant. |
| HR Manager | Organizational analytics and exports through the default `hr_review_reports` and `hr_export_reports` grants |
| System Administrator | The aggregate administrative workbook from the technical dashboard |

Report lookups apply role and assignment restrictions independently of requested filters and report IDs. HR and supervisors can export submitted snapshots. Staff can also export their own draft or saved corrections. Approval history retains the recorded reviewer and timestamp for each revision. The technical workbook contains organization and department totals.

## Filtering and metrics

Dates use the report’s reporting date. A date range includes both endpoints. Month and year selections constrain any explicit date range. With no date, month, or year input, the dashboard shows the current reporting month. Use a reporting year to compare monthly trends. Supervisor filters use current assignments, and approval statistics group recorded decisions by their original reviewer.

Monthly departmental summaries reflect all workflow states for the selected dates, departments, staff and supervisors. The status filter controls the other analytics and exports. A draft does not count as submitted. Pending supervisor and HR reviews, returned reports, and HR-approved reports all count as submissions. Supervisor approvals count reports with an approval on the latest revision. Reviewer activity counts actual approval and return events across revisions.

Expected submissions use the current eligible roster, account creation dates, and each staff member's effective work schedule or the default calendar. The reporting deadline determines when today first becomes due. The system has no historical department roster, so these figures are a planning estimate for earlier periods. Actual submissions use their recorded report department and include non-working dates. Completion is the percentage of expected staff-date submissions fulfilled within the eligible roster. Missing counts only due staff-date submissions. A blank completion rate means there were no expected submissions. See [staff work schedules](staff-work-schedules.md).

Attendance uses the latest submitted revision and reflects staff-entered times. Late arrivals exceed the configured official start time plus grace. Overnight records and records without a usable arrival time are excluded from lateness assessments. Historical arrival classifications use the current settings.

## Downloads

The individual PDF contains the application branding, report facts, staff-entered attendance, work and challenges, and revision-linked supervisor and HR decisions. It embeds the configured logo and uses no remote resources.

The Excel options include individual reports with approval history, departmental monthly reports, attendance, approval activity, staff submission statistics, and aggregate administration. Text is written as string cells, including fields that begin with Excel formula characters. Workbook totals come from the same reporting calculations as the dashboard.

Date ranges allow up to 366 days. Queries allow up to 15,000 reports or roster entries per selection. Workbooks cap body cells at 60,000 so operators can narrow a large request before generation.

## Verification

The integration suite exercises date ranges, month, year, status and assignment filters, pending versus missing counts, lateness, role boundaries, PDF downloads, and every Excel export. It reopens the generated workbooks and verifies that formula-like text is stored as a string cell.
