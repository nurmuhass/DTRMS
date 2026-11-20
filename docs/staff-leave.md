# Staff leave and report exemptions

Open **HR → Staff leave**. The HR Manager role needs the organization management grant to register or edit pending leave, decide leave, cancel an approval, and download supporting documents. Staff, supervisors, HR Officers and technical administrators have no leave access by default.

1. Register an existing staff member, leave type, inclusive start and end dates, reason, and an optional supporting document. New registrations enter the approval queue as pending.
2. Edit the record while it is pending. Open the queue to approve or reject. Rejection requires a reason. An overlapping approved period for the same staff member requires explicit authorization and a recorded reason.
3. Use the current, upcoming and history views, or choose a month in the calendar. An approved calendar day shows the number of distinct staff on leave and links to their approved records for that date.
4. Cancel an approved record with a reason when needed. Cancellation preserves the original approver, decision date and document. HR can view cancelled and rejected records in history.

## Reporting behavior

Only approved leave removes expected submission days. The exemption is the intersection of the inclusive leave period and the individual's scheduled workdays. Approved overlaps exempt each affected date once. A pending, rejected or cancelled record has no effect on expectations. Cancellation restores expectations for the affected dates except where other approved leave still covers them.

Existing submissions remain in history and actual report counts. A report on an exempt date does not satisfy an expectation on another date. The reminder worker suppresses missing-report reminders on approved leave dates. Messages already sent before a decision stay in delivery history.

## Deployment

- Apply migrations with the normal migration account. Grant the HR web identity SELECT, INSERT and UPDATE on staff_leaves. The reminder worker needs SELECT. With column-level privileges, allow UPDATE for leave_type, start_date, end_date, reason, attachment_path, status, decision_note, overlap_reason, approved_by, approved_at and lock_version.
- For uploads, set LEAVE_STORAGE_DIR to an existing absolute directory outside the checkout and every served document root or alias. The application rejects common web root locations. Grant exclusive access to the PHP identity and approved backup operators using a protected volume and restrictive NTFS ACLs, or mode 0700 for the PHP-owned directory on Unix. Keep Fileinfo enabled. In PHP configure upload_max_filesize=6M and post_max_size=8M, and use private upload temp storage.
- Each record can hold one PDF, PNG or JPEG, up to 5 MB. The server detects its type, discards the supplied filename and serves it only as an attachment through an authorized route. Leave registration without a document is available independently of storage configuration.
- Back up the private document directory alongside MySQL. Coordinate the snapshot while HR writes are paused so database references and files match, protect copies as confidential staff records and verify a download in staging after recovery.
- Use a dedicated temporary LEAVE_STORAGE_DIR for integration tests. With it configured, the suite exercises a rejected disguised upload, authenticated download and unauthorized access checks, in addition to leave decisions and exemption calculations.
