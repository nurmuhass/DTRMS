INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'hr_manager' AND p.code = 'hr_export_reports'
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;
