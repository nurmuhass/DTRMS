CREATE TABLE IF NOT EXISTS report_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    stage ENUM('supervisor','hr') NOT NULL,
    decision ENUM('approved','returned') NOT NULL,
    comments TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (report_id, revision_number, stage),
    FOREIGN KEY (report_id, revision_number) REFERENCES report_revisions(report_id, revision_number),
    FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
