CREATE TRIGGER users_track_account_status
AFTER UPDATE ON users FOR EACH ROW
BEGIN
    IF OLD.is_active <> NEW.is_active THEN
        INSERT INTO user_account_status_history (user_id, is_active) VALUES (NEW.id, NEW.is_active);
    END IF;
END;
