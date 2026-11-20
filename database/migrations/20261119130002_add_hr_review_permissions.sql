INSERT INTO permissions (code, description) VALUES
    ('hr_review_reports', 'View organizational reports and the HR review queue'),
    ('hr_decide_reports', 'Approve or return supervisor-approved reports')
ON DUPLICATE KEY UPDATE description = permissions.description;
