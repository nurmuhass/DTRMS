CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    subject_user_id BIGINT UNSIGNED NULL,
    event VARCHAR(80) NOT NULL,
    object_type VARCHAR(80) NULL,
    object_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (event, created_at),
    INDEX (object_type, object_id, created_at),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    FOREIGN KEY (subject_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
