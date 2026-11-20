INSERT INTO roles (code, name) VALUES
    ('staff', 'Staff'),
    ('supervisor', 'Supervisor'),
    ('hr_officer', 'HR Officer'),
    ('hr_manager', 'HR Manager'),
    ('system_admin', 'System Administrator')
ON DUPLICATE KEY UPDATE id = roles.id;
