ALTER TABLE departments
    ADD COLUMN hod_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT departments_hod_fk FOREIGN KEY (hod_id) REFERENCES users(id);
