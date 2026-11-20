CREATE TABLE staff_weekly_schedules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 week_start DATE NOT NULL,
 week_end DATE NOT NULL,
 status ENUM('draft','submitted','approved','locked','cancelled') NOT NULL DEFAULT 'draft',
 submitted_at TIMESTAMP NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at TIMESTAMP NULL,
 unlocked_at TIMESTAMP NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE (user_id,week_start),
 INDEX weekly_schedule_state (week_start,status,user_id),
 CHECK (WEEKDAY(week_start)=0 AND DATEDIFF(week_end,week_start)=6),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
