INSERT INTO permissions (code, description) VALUES
    ('hr_export_reports', 'Export organizational staff reports and analytics')
ON DUPLICATE KEY UPDATE description = permissions.description;
