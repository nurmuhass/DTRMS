CREATE TABLE staff_work_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    assignment_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status ENUM('assigned','cancelled') NOT NULL DEFAULT 'assigned',
    exception_reason VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_day TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'assigned' THEN day_of_week END) STORED,
    UNIQUE KEY schedule_assigned_day (user_id, effective_from, assigned_day),
    INDEX schedule_period_lookup (user_id, status, effective_from, effective_to),
    CHECK (day_of_week BETWEEN 1 AND 7),
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
