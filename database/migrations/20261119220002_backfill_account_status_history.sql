INSERT INTO user_account_status_history (user_id, is_active, changed_at)
SELECT u.id, a.event = 'account_reactivated', a.created_at
FROM audit_logs a JOIN users u ON u.id = a.object_id
WHERE a.object_type = 'user' AND a.event IN ('account_deactivated','account_reactivated')
ORDER BY a.created_at, a.id;
