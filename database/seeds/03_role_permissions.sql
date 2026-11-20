INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('portal_access', 'password_change', 'session_logout')
    OR (r.code = 'staff' AND p.code = 'staff_workspace')
    OR (r.code = 'supervisor' AND p.code = 'supervisor_workspace')
    OR (r.code = 'hr_officer' AND p.code = 'hr_officer_workspace')
    OR (r.code = 'hr_manager' AND p.code = 'hr_manager_workspace')
    OR (r.code = 'system_admin' AND p.code = 'technical_workspace')
WHERE r.code IN ('staff', 'supervisor', 'hr_officer', 'hr_manager', 'system_admin')
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;
