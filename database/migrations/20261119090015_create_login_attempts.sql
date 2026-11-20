CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address VARCHAR(45) CHARACTER SET ascii NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (identifier_hash, succeeded, attempted_at),
    INDEX (ip_address, succeeded, attempted_at),
    INDEX (attempted_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
