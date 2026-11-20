INSERT INTO permissions (code, description) VALUES
    ('portal_access', 'Access the authenticated portal'),
    ('password_change', 'Change own password'),
    ('session_logout', 'End own session'),
    ('staff_workspace', 'Open staff workspace'),
    ('supervisor_workspace', 'Open supervisor workspace'),
    ('hr_officer_workspace', 'Open HR Officer workspace'),
    ('hr_manager_workspace', 'Open HR Manager workspace'),
    ('technical_workspace', 'Open technical administration workspace')
ON DUPLICATE KEY UPDATE id = permissions.id;
