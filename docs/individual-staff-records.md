# Individual staff records

HR Managers open a staff member from **Submission monitoring**. Supervisors open a name from **Assigned staff**. Staff use **Weekly and monthly records** in their portal. The profile shows current department, supervisor, account status, schedule and approved leave.

Use **View week** to select an ISO Monday-to-Sunday week or **View month** to select a calendar month and year. The selected period shows expected and actual submissions, outstanding due dates, compliance, reports and attendance, approval decisions and scheduled days exempted by approved leave. The page also shows the recent weekly and monthly summaries and leave history.

The reporting date determines inclusion in a period. A report for December 31 submitted January 2 stays in December’s monthly record. A week that spans December and January can include both months. Monthly expectations use only scheduled workdays in that calendar month, with effective schedule changes, holidays, approved leave and the reporting cutoff applied to each date. Attendance summaries are based on staff-entered values from the latest submission snapshot where available.

## Access and exports

Staff can open and export their own records. Supervisors need the supervisor workspace grant and a current assignment to the staff member. Supervisors see approved leave dates and submission exemptions. HR Managers need organization management permission for the profile and the applicable HR review and export grants for downloads. Daily report links also respect existing review permissions. Technical administrators and read-only HR Officers have no individual profile access by default.

Export the selected period as PDF or Excel. Both formats contain the reporting dates, totals, report references, submitted descriptions, achievements and challenges, recorded attendance, decisions and exemption dates. The Excel workbook has dedicated Summary, Daily reports, Approval history and Leave exemptions sheets. Both formats keep leave reasons and supporting documents out of the export. The generated PDF uses the organization name and does not fetch remote content.

The export endpoint is a CSRF-protected POST. Scope is checked against the submitted staff ID, and supervisor assignment is checked again before sending the file. Successful generation records staff_period_exported with actor, staff member, format and period dates in the existing audit log. The audit event contains no report or leave text. Deployment requires the existing monitoring migrations, audit INSERT grant, Dompdf and PhpSpreadsheet. This phase adds no migration.

See [submission monitoring](submission-monitoring.md) for the shared compliance definitions and current-roster limitations. Report-level department names retain the report snapshot where present.
