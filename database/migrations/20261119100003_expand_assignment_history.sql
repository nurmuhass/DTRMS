ALTER TABLE supervisor_assignments
    ADD COLUMN previous_supervisor_id BIGINT UNSIGNED NULL,
    ADD COLUMN change_reason VARCHAR(500) NULL,
    ADD CONSTRAINT assignments_previous_supervisor_fk FOREIGN KEY (previous_supervisor_id) REFERENCES users(id);
