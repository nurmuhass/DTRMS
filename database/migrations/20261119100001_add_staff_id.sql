ALTER TABLE users
    ADD COLUMN staff_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    ADD UNIQUE KEY users_staff_id_unique (staff_id);
