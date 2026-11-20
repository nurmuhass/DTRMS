INSERT INTO report_revisions (report_id, revision_number, revised_by, content, snapshot, submitted_at, working_minutes)
SELECT r.id, 1, r.staff_id, r.content,
    JSON_OBJECT('department_id', r.department_id, 'report_date', r.report_date, 'content', r.content,
        'arrival_time', r.arrival_time, 'work_start_time', r.work_start_time, 'work_end_time', r.work_end_time,
        'achievement', r.achievement, 'challenges', r.challenges, 'declared', r.declared,
        'overnight', r.overnight, 'working_minutes', r.working_minutes, 'reporter_name', r.reporter_name,
        'reporter_staff_code', r.reporter_staff_code, 'department_name_snapshot', r.department_name_snapshot),
    r.submitted_at, r.working_minutes
FROM daily_reports r
WHERE r.status = 'submitted' AND NOT EXISTS (SELECT 1 FROM report_revisions v WHERE v.report_id = r.id);
