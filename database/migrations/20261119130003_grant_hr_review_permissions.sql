INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE (r.code IN ('hr_officer','hr_manager') AND p.code = 'hr_review_reports')
    OR (r.code = 'hr_manager' AND p.code = 'hr_decide_reports')
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;
