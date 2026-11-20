ALTER TABLE email_logs
    ADD COLUMN queue_id BIGINT UNSIGNED NULL,
    ADD COLUMN attempt TINYINT UNSIGNED NULL,
    ADD UNIQUE KEY email_queue_attempt_unique (queue_id, attempt),
    ADD CONSTRAINT email_logs_queue_fk FOREIGN KEY (queue_id) REFERENCES email_queue(id);
