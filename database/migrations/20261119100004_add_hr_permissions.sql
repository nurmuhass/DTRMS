INSERT INTO permissions (code, description) VALUES
    ('hr_manage_organization', 'Manage departments, organizational accounts and supervisor assignments'),
    ('hr_manage_officers', 'Provision and manage HR Officer accounts')
ON DUPLICATE KEY UPDATE description = permissions.description;
