ALTER TABLE report_revisions
    ADD COLUMN snapshot JSON NULL,
    ADD COLUMN submitted_at TIMESTAMP NULL,
    ADD COLUMN working_minutes SMALLINT UNSIGNED NULL;
