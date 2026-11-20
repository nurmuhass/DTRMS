CREATE TRIGGER daily_reports_guard_hr_status_update
BEFORE UPDATE ON daily_reports FOR EACH ROW
BEGIN
    IF NEW.status IN ('hr_approved','returned_by_hr')
        AND (OLD.status <> NEW.status OR OLD.staff_id <> NEW.staff_id)
        AND NOT EXISTS (
            SELECT 1 FROM report_approvals h
            JOIN report_approvals s ON s.report_id = h.report_id AND s.revision_number = h.revision_number
                AND s.stage = 'supervisor' AND s.decision = 'approved'
            WHERE h.report_id = OLD.id AND h.stage = 'hr'
                AND h.decision = IF(NEW.status = 'hr_approved','approved','returned')
                AND h.revision_number = (SELECT MAX(v.revision_number) FROM report_revisions v WHERE v.report_id = OLD.id)
                AND OLD.status IN ('pending_hr','supervisor_approved') AND OLD.staff_id = NEW.staff_id
        ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final report status requires both approval stages for this revision.';
    END IF;
END;
