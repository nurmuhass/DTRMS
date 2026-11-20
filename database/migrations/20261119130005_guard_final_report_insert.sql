CREATE TRIGGER daily_reports_guard_hr_status_insert
BEFORE INSERT ON daily_reports FOR EACH ROW
BEGIN
    IF NEW.status IN ('hr_approved','returned_by_hr') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new report cannot already have an HR decision.';
    END IF;
END;
