CREATE TRIGGER staff_work_schedules_guard_overlap
BEFORE INSERT ON staff_work_schedules FOR EACH ROW
BEGIN
    IF NEW.status = 'assigned' AND EXISTS (
        SELECT 1 FROM staff_work_schedules s
        WHERE s.user_id = NEW.user_id AND s.status = 'assigned' AND s.assignment_key <> NEW.assignment_key
            AND s.effective_from <= COALESCE(NEW.effective_to, '9999-12-31')
            AND (s.effective_to IS NULL OR s.effective_to >= NEW.effective_from)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The staff schedule overlaps an assigned period.';
    END IF;
END;
