CREATE TABLE IF NOT EXISTS report_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    revised_by BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (report_id, revision_number),
    FOREIGN KEY (report_id) REFERENCES daily_reports(id),
    FOREIGN KEY (revised_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
