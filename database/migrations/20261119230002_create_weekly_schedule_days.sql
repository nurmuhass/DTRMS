CREATE TABLE staff_weekly_schedule_days (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 weekly_schedule_id BIGINT UNSIGNED NOT NULL,
 scheduled_date DATE NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE (weekly_schedule_id,scheduled_date),
 INDEX (scheduled_date),
 FOREIGN KEY (weekly_schedule_id) REFERENCES staff_weekly_schedules(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
