CREATE TRIGGER report_approvals_guard_hr_insert
BEFORE INSERT ON report_approvals FOR EACH ROW
BEGIN
    IF NEW.stage = 'hr' AND NOT EXISTS (
        SELECT 1 FROM daily_reports r
        JOIN report_approvals s ON s.report_id = r.id AND s.revision_number = NEW.revision_number
            AND s.stage = 'supervisor' AND s.decision = 'approved'
        WHERE r.id = NEW.report_id AND r.status IN ('pending_hr','supervisor_approved')
            AND NEW.revision_number = (SELECT MAX(v.revision_number) FROM report_revisions v WHERE v.report_id = r.id)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR decisions require supervisor approval on the pending revision.';
    END IF;
END;
