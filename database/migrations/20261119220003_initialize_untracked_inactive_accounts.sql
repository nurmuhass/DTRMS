INSERT INTO user_account_status_history (user_id, is_active, changed_at)
SELECT u.id, FALSE, u.updated_at
FROM users u
WHERE u.is_active = FALSE AND NOT EXISTS (SELECT 1 FROM user_account_status_history h WHERE h.user_id = u.id);
