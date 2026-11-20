CREATE TABLE IF NOT EXISTS department_overnight_schedules (
    department_id BIGINT UNSIGNED PRIMARY KEY,
    earliest_arrival TIME NOT NULL,
    latest_end TIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (earliest_arrival > latest_end AND earliest_arrival < '24:00:00' AND latest_end >= '00:00:00'),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
