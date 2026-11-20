CREATE TABLE IF NOT EXISTS supervisor_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    supervisor_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    current_staff_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN ended_at IS NULL THEN staff_id END) STORED,
    UNIQUE (current_staff_id),
    INDEX (staff_id, started_at),
    INDEX (supervisor_id, ended_at),
    CHECK (staff_id <> supervisor_id),
    CHECK (ended_at IS NULL OR ended_at >= started_at),
    FOREIGN KEY (staff_id) REFERENCES users(id),
    FOREIGN KEY (supervisor_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
