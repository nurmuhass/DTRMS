ALTER TABLE daily_reports
    MODIFY COLUMN status ENUM('draft','submitted','supervisor_approved','returned','hr_approved',
        'pending_hr','returned_by_supervisor','returned_by_hr') NOT NULL DEFAULT 'draft';
