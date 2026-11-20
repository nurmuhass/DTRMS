CREATE TABLE IF NOT EXISTS scheduled_job_executions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    scheduled_for TIMESTAMP NOT NULL,
    attempt INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('running','succeeded','failed') NOT NULL DEFAULT 'running',
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    details VARCHAR(500) NULL,
    UNIQUE (job_name, scheduled_for, attempt),
    INDEX (status, started_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
