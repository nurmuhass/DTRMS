CREATE TRIGGER daily_reports_guard_submit_update
BEFORE UPDATE ON daily_reports FOR EACH ROW
BEGIN
    IF NEW.status = 'submitted' AND (OLD.status <> 'submitted' OR NEW.staff_id <> OLD.staff_id) AND NOT EXISTS (
        SELECT 1 FROM supervisor_assignments a
        JOIN users u ON u.id = a.supervisor_id AND u.is_active = TRUE
        JOIN roles r ON r.id = u.role_id AND r.code = 'supervisor'
        WHERE a.current_staff_id = NEW.staff_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An active supervisor assignment is required before submission.';
    END IF;
END;
