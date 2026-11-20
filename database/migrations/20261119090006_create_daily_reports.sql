CREATE TABLE IF NOT EXISTS daily_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    content TEXT NOT NULL,
    status ENUM('draft','submitted','supervisor_approved','returned','hr_approved') NOT NULL DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (staff_id, report_date),
    INDEX (department_id, status, report_date),
    FOREIGN KEY (staff_id) REFERENCES users(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
