-- DTRMS fresh install for an empty MySQL 8 database selected in phpMyAdmin.
-- Contains schema and baseline seeds only. Import once. Create the first administrator separately.
SET SESSION time_zone = '+00:00';
SET NAMES utf8mb4;
CREATE TABLE schema_migrations (version VARCHAR(128) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;

-- 20261119090001_create_departments
CREATE TABLE IF NOT EXISTS departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090001_create_departments', '86d12190bbda2799e345f10c42055cfb2a5f3cf5a831cf7f158ab8145711f83b');

-- 20261119090002_create_roles
CREATE TABLE IF NOT EXISTS roles (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090002_create_roles', '22b74a271ca439b2e0f63f18142771142595159395300bd79aff62c37a4b1660');

-- 20261119090003_create_permissions
CREATE TABLE IF NOT EXISTS permissions (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090003_create_permissions', 'c08c6156a095061f1b709f2eb683c12881e6156ac15c23bdb60eaf601a57cb29');

-- 20261119090004_create_role_permissions
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090004_create_role_permissions', '36a2259974b6ed969bf7ab5524d47a3c7807692c2100e1e295c4606f520a99ec');

-- 20261119090005_create_users
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id SMALLINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
    credential_version INT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (department_id, is_active),
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090005_create_users', '7e66ae4a15010de35e624c6fe4c55531109ca01ada793e24cdab4e51c5df8970');

-- 20261119090006_create_daily_reports
CREATE TABLE IF NOT EXISTS daily_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    content TEXT NOT NULL,
    status ENUM('draft','submitted','supervisor_approved','returned','hr_approved') NOT NULL DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (staff_id, report_date),
    INDEX (department_id, status, report_date),
    FOREIGN KEY (staff_id) REFERENCES users(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090006_create_daily_reports', '89e922448c5f1587ef50f39d106afcc25420b202eb4b5a785552b0b0e875f7f9');

-- 20261119090007_create_report_revisions
CREATE TABLE IF NOT EXISTS report_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    revised_by BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (report_id, revision_number),
    FOREIGN KEY (report_id) REFERENCES daily_reports(id),
    FOREIGN KEY (revised_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090007_create_report_revisions', '6e82b6ff7448804982ff3d46d480ccdeb411e7246a320c874c5768a877053316');

-- 20261119090008_create_report_approvals
CREATE TABLE IF NOT EXISTS report_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    stage ENUM('supervisor','hr') NOT NULL,
    decision ENUM('approved','returned') NOT NULL,
    comments TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (report_id, revision_number, stage),
    FOREIGN KEY (report_id, revision_number) REFERENCES report_revisions(report_id, revision_number),
    FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090008_create_report_approvals', '4c71d4de8b80abd7d58378feff80bd2c9f5ad20d64de554250e842559a517865');

-- 20261119090009_create_notifications
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NULL,
    type VARCHAR(80) NOT NULL,
    message VARCHAR(500) NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, read_at, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (report_id) REFERENCES daily_reports(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090009_create_notifications', '7d8c49a76f694d9dbb2239e1838681605582823b7ac2c5085cd122e10795d416');

-- 20261119090010_create_audit_logs
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
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090010_create_audit_logs', '8030fd68c3e660521b5197959aa59023d47bd257314de886bdf9cdf7ed4be9db');

-- 20261119090011_create_email_logs
CREATE TABLE IF NOT EXISTS email_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    recipient VARCHAR(254) NOT NULL,
    template VARCHAR(80) NOT NULL,
    status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(255) NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (status, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090011_create_email_logs', 'dd878d83cea45c5b9f74bf409bc5d91591424fac169b6d1f2ccd974dca44d72b');

-- 20261119090012_create_system_settings
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090012_create_system_settings', '1e4c4f833082628732cb19f33297d08728044e30f0021d2f8cd82a2f3d802935');

-- 20261119090013_create_user_sessions
CREATE TABLE IF NOT EXISTS user_sessions (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    credential_version INT UNSIGNED NOT NULL,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, expires_at),
    INDEX (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090013_create_user_sessions', '33ff10203392a813ae17e9e4829377e13ad506db95ec863b1451daccba2615ef');

-- 20261119090014_create_supervisor_assignments
CREATE TABLE IF NOT EXISTS supervisor_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    supervisor_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    current_staff_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN ended_at IS NULL THEN staff_id END) STORED,
    UNIQUE (current_staff_id),
    INDEX (staff_id, started_at),
    INDEX (supervisor_id, ended_at),
    CHECK (staff_id <> supervisor_id),
    CHECK (ended_at IS NULL OR ended_at >= started_at),
    FOREIGN KEY (staff_id) REFERENCES users(id),
    FOREIGN KEY (supervisor_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090014_create_supervisor_assignments', '0ba431ec8f1f64151ef3c4f07a5073b8ef2515a46c318ca84c506ffd0377e9fa');

-- 20261119090015_create_login_attempts
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
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090015_create_login_attempts', '19a6a7139c5d5f8e2762ea921392409993e2b0de21a283fca61fb67f984168ac');

-- 20261119090016_create_scheduled_job_executions
CREATE TABLE IF NOT EXISTS scheduled_job_executions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    scheduled_for TIMESTAMP NOT NULL,
    attempt INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('running','succeeded','failed') NOT NULL DEFAULT 'running',
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    details VARCHAR(500) NULL,
    UNIQUE (job_name, scheduled_for, attempt),
    INDEX (status, started_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090016_create_scheduled_job_executions', 'e89629e4b04726a91473bbde5dfc3863b1d091bc254e4a863c0b54431e3f4021');

-- 20261119090017_create_password_reset_tokens
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119090017_create_password_reset_tokens', '00e6c59f6e3e052b89153c9fd77a32126c0145b0a7118f8d5ed20a6a338df2cb');

-- 20261119100001_add_staff_id
ALTER TABLE users
    ADD COLUMN staff_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    ADD UNIQUE KEY users_staff_id_unique (staff_id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119100001_add_staff_id', 'df7f4ccffd09b23c7a0a5e27b28909d7056b3bcd890008dc993cb70e06a71823');

-- 20261119100002_add_department_hod
ALTER TABLE departments
    ADD COLUMN hod_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT departments_hod_fk FOREIGN KEY (hod_id) REFERENCES users(id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119100002_add_department_hod', '04086f8b1e0c540604f0cde194bd1c64588e63cda11d0c65e77faf12c61ef7b7');

-- 20261119100003_expand_assignment_history
ALTER TABLE supervisor_assignments
    ADD COLUMN previous_supervisor_id BIGINT UNSIGNED NULL,
    ADD COLUMN change_reason VARCHAR(500) NULL,
    ADD CONSTRAINT assignments_previous_supervisor_fk FOREIGN KEY (previous_supervisor_id) REFERENCES users(id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119100003_expand_assignment_history', '2112b8d6e5ab578b6c3ee452995f433f4e8d4503d049b8ef8d5eccc6c9b2f184');

-- 20261119100004_add_hr_permissions
INSERT INTO permissions (code, description) VALUES
    ('hr_manage_organization', 'Manage departments, organizational accounts and supervisor assignments'),
    ('hr_manage_officers', 'Provision and manage HR Officer accounts')
ON DUPLICATE KEY UPDATE description = permissions.description;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119100004_add_hr_permissions', '8b8790b8d31d3a5a29fab6d2116a66cef118c090b00d37c4db5d52b486693f97');

-- 20261119100005_grant_hr_permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'hr_manager' AND p.code IN ('hr_manage_organization', 'hr_manage_officers')
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119100005_grant_hr_permissions', '6a11360def5bf39c9933b4b6a493a0e5216be1fa6c4ab07f9a8e991022f20a26');

-- 20261119100006_guard_report_submission_insert
DELIMITER $$
CREATE TRIGGER daily_reports_guard_submit_insert
BEFORE INSERT ON daily_reports FOR EACH ROW
BEGIN
    IF NEW.status = 'submitted' AND NOT EXISTS (
        SELECT 1 FROM supervisor_assignments a
        JOIN users u ON u.id = a.supervisor_id AND u.is_active = TRUE
        JOIN roles r ON r.id = u.role_id AND r.code = 'supervisor'
        WHERE a.current_staff_id = NEW.staff_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An active supervisor assignment is required before submission.';
    END IF;
END$$
DELIMITER ;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119100006_guard_report_submission_insert', '1105114f1aae44049f014b9aade1e0747b91b1589b24a5745280b70c6a0d53a3');

-- 20261119100007_guard_report_submission_update
DELIMITER $$
CREATE TRIGGER daily_reports_guard_submit_update
BEFORE UPDATE ON daily_reports FOR EACH ROW
BEGIN
    IF NEW.status = 'submitted' AND (OLD.status <> 'submitted' OR NEW.staff_id <> OLD.staff_id) AND NOT EXISTS (
        SELECT 1 FROM supervisor_assignments a
        JOIN users u ON u.id = a.supervisor_id AND u.is_active = TRUE
        JOIN roles r ON r.id = u.role_id AND r.code = 'supervisor'
        WHERE a.current_staff_id = NEW.staff_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An active supervisor assignment is required before submission.';
    END IF;
END$$
DELIMITER ;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119100007_guard_report_submission_update', '6b5bcd3c438e5b049ca1266f36d6347167ddd6af4579fd22c653be188901c3a6');

-- 20261119110001_add_report_fields
ALTER TABLE daily_reports
    ADD COLUMN reference_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE KEY daily_reports_reference_unique (reference_number),
    ADD COLUMN reporter_name VARCHAR(150) NULL,
    ADD COLUMN reporter_staff_code VARCHAR(40) NULL,
    ADD COLUMN department_name_snapshot VARCHAR(150) NULL,
    ADD COLUMN arrival_time TIME NULL,
    ADD COLUMN work_start_time TIME NULL,
    ADD COLUMN work_end_time TIME NULL,
    ADD COLUMN achievement TEXT NULL,
    ADD COLUMN challenges TEXT NULL,
    ADD COLUMN declared BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN overnight BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN working_minutes SMALLINT UNSIGNED NULL,
    ADD COLUMN first_submitted_at TIMESTAMP NULL,
    ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 0;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119110001_add_report_fields', '81e396a9c13cc669bc915a357516ef157c3169de36fd182a112ff55a06ad71c1');

-- 20261119110002_backfill_report_references
UPDATE daily_reports
SET reference_number = CONCAT('DTR-LEGACY-', id), first_submitted_at = submitted_at
WHERE reference_number IS NULL;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119110002_backfill_report_references', '4200281b7f2344490e0ad746c948ff72392e62aeab76bc196b28f3d35ad4ab3e');

-- 20261119110003_add_revision_snapshot
ALTER TABLE report_revisions
    ADD COLUMN snapshot JSON NULL,
    ADD COLUMN submitted_at TIMESTAMP NULL,
    ADD COLUMN working_minutes SMALLINT UNSIGNED NULL;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119110003_add_revision_snapshot', 'ed0bc6d5279c8eb7318319687afc5130a5d956f024194dd4e02af79d5513bcfa');

-- 20261119110004_add_future_report_authorization
ALTER TABLE users ADD COLUMN future_reports_through DATE NULL;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119110004_add_future_report_authorization', 'fa5a9c467bdb3860766e708f8b7bc09f6b99fa724174a95048a80fc13c2a8c72');

-- 20261119110005_create_department_overnight_schedules
CREATE TABLE IF NOT EXISTS department_overnight_schedules (
    department_id BIGINT UNSIGNED PRIMARY KEY,
    earliest_arrival TIME NOT NULL,
    latest_end TIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (earliest_arrival > latest_end AND earliest_arrival < '24:00:00' AND latest_end >= '00:00:00'),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119110005_create_department_overnight_schedules', 'ce329a3d88f3a63461d6967897a17bb30c81db0de7ea0c386fb836420d84f05c');

-- 20261119120001_extend_report_workflow
ALTER TABLE daily_reports
    MODIFY COLUMN status ENUM('draft','submitted','supervisor_approved','returned','hr_approved',
        'pending_hr','returned_by_supervisor') NOT NULL DEFAULT 'draft';
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119120001_extend_report_workflow', '796590542b9a4b0dd40a1402736d02f51602c060aed542123aec4e383898f6fe');

-- 20261119120002_backfill_pending_report_revisions
INSERT INTO report_revisions (report_id, revision_number, revised_by, content, snapshot, submitted_at, working_minutes)
SELECT r.id, 1, r.staff_id, r.content,
    JSON_OBJECT('department_id', r.department_id, 'report_date', r.report_date, 'content', r.content,
        'arrival_time', r.arrival_time, 'work_start_time', r.work_start_time, 'work_end_time', r.work_end_time,
        'achievement', r.achievement, 'challenges', r.challenges, 'declared', r.declared,
        'overnight', r.overnight, 'working_minutes', r.working_minutes, 'reporter_name', r.reporter_name,
        'reporter_staff_code', r.reporter_staff_code, 'department_name_snapshot', r.department_name_snapshot),
    r.submitted_at, r.working_minutes
FROM daily_reports r
WHERE r.status = 'submitted' AND NOT EXISTS (SELECT 1 FROM report_revisions v WHERE v.report_id = r.id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119120002_backfill_pending_report_revisions', '90c2d2f578456ab14206126b91d0ba389d8e0833196f767a9292ef616cb668aa');

-- 20261119120003_index_supervisor_activity
ALTER TABLE report_approvals ADD INDEX reviewer_stage_activity (reviewer_id, stage, decision, created_at);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119120003_index_supervisor_activity', 'befd1c191580a854dfdd165b1433a7706c950984f16b18d8b8aea740aef76d20');

-- 20261119130001_extend_hr_workflow
ALTER TABLE daily_reports
    MODIFY COLUMN status ENUM('draft','submitted','supervisor_approved','returned','hr_approved',
        'pending_hr','returned_by_supervisor','returned_by_hr') NOT NULL DEFAULT 'draft';
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119130001_extend_hr_workflow', 'be929e23dbb322d552c9f4c67df5c977a9401ef6815cc2d0a799be5fd0e617f8');

-- 20261119130002_add_hr_review_permissions
INSERT INTO permissions (code, description) VALUES
    ('hr_review_reports', 'View organizational reports and the HR review queue'),
    ('hr_decide_reports', 'Approve or return supervisor-approved reports')
ON DUPLICATE KEY UPDATE description = permissions.description;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119130002_add_hr_review_permissions', '0bfeafecd5c8421f8dfd5b1fa70934ac38b26e4edb2927de82952647311997cb');

-- 20261119130003_grant_hr_review_permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE (r.code IN ('hr_officer','hr_manager') AND p.code = 'hr_review_reports')
    OR (r.code = 'hr_manager' AND p.code = 'hr_decide_reports')
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119130003_grant_hr_review_permissions', '32c1dfd751d9fb013adbbdb2278c9d73323f529f9984af54d7dc3d1e7663fa59');

-- 20261119130004_guard_hr_decision
DELIMITER $$
CREATE TRIGGER report_approvals_guard_hr_insert
BEFORE INSERT ON report_approvals FOR EACH ROW
BEGIN
    IF NEW.stage = 'hr' AND NOT EXISTS (
        SELECT 1 FROM daily_reports r
        JOIN report_approvals s ON s.report_id = r.id AND s.revision_number = NEW.revision_number
            AND s.stage = 'supervisor' AND s.decision = 'approved'
        WHERE r.id = NEW.report_id AND r.status IN ('pending_hr','supervisor_approved')
            AND NEW.revision_number = (SELECT MAX(v.revision_number) FROM report_revisions v WHERE v.report_id = r.id)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR decisions require supervisor approval on the pending revision.';
    END IF;
END$$
DELIMITER ;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119130004_guard_hr_decision', '0bc7dea7b22e772ea9db2ef444e2b6ba2fd7248303fb93fc611a3eb3ddeca154');

-- 20261119130005_guard_final_report_insert
DELIMITER $$
CREATE TRIGGER daily_reports_guard_hr_status_insert
BEFORE INSERT ON daily_reports FOR EACH ROW
BEGIN
    IF NEW.status IN ('hr_approved','returned_by_hr') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new report cannot already have an HR decision.';
    END IF;
END$$
DELIMITER ;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119130005_guard_final_report_insert', 'f3dd60f73f47ff8468b37a27c1faf78efae56d0c4597596ab0c262f2bc01e946');

-- 20261119130006_guard_final_report_update
DELIMITER $$
CREATE TRIGGER daily_reports_guard_hr_status_update
BEFORE UPDATE ON daily_reports FOR EACH ROW
BEGIN
    IF NEW.status IN ('hr_approved','returned_by_hr')
        AND (OLD.status <> NEW.status OR OLD.staff_id <> NEW.staff_id)
        AND NOT EXISTS (
            SELECT 1 FROM report_approvals h
            JOIN report_approvals s ON s.report_id = h.report_id AND s.revision_number = h.revision_number
                AND s.stage = 'supervisor' AND s.decision = 'approved'
            WHERE h.report_id = OLD.id AND h.stage = 'hr'
                AND h.decision = IF(NEW.status = 'hr_approved','approved','returned')
                AND h.revision_number = (SELECT MAX(v.revision_number) FROM report_revisions v WHERE v.report_id = OLD.id)
                AND OLD.status IN ('pending_hr','supervisor_approved') AND OLD.staff_id = NEW.staff_id
        ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Final report status requires both approval stages for this revision.';
    END IF;
END$$
DELIMITER ;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119130006_guard_final_report_update', '755564a6d0d02f0f45b2243649348ffb0ec65b49894df8e3b05ac7b5bcf8e55d');

-- 20261119130007_index_hr_review_queue
ALTER TABLE daily_reports ADD INDEX report_status_date (status, report_date, id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119130007_index_hr_review_queue', '297fdc6bf50cc39ed3b4558d52bd969680ba87ce5db105981a4a2c78b4af0e39');

-- 20261119140001_initialize_operational_settings
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('operational_configuration', '{}')
ON DUPLICATE KEY UPDATE setting_value = system_settings.setting_value;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119140001_initialize_operational_settings', '8dd0a2e0a826fb22d4937430ec8b9adb977a4d44099bcd6432251a1bf4d0f332');

-- 20261119140002_create_application_branding
CREATE TABLE IF NOT EXISTS application_branding (
    id TINYINT UNSIGNED PRIMARY KEY,
    logo MEDIUMBLOB NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119140002_create_application_branding', '2c496781e9de0d6ccfc1567b30feb1bef96bb6d075fae3bfff7d27afaeda77a2');

-- 20261119140003_initialize_application_branding
INSERT INTO application_branding (id) VALUES (1)
ON DUPLICATE KEY UPDATE id = application_branding.id;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119140003_initialize_application_branding', '4042719f4320b37531e434afdc20302f9ad8f8a7a9890b6c3f66fc04ee6e2b63');

-- 20261119150001_create_notification_queue
CREATE TABLE IF NOT EXISTS notification_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NULL,
    type VARCHAR(80) NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP NULL,
    INDEX (delivered_at, id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (report_id) REFERENCES daily_reports(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119150001_create_notification_queue', '129996a07a958287a3b74e3f420dfe07e7f3cd9f885c58830f1c0aa5876d7994');

-- 20261119150002_link_delivered_notifications
ALTER TABLE notifications
    ADD COLUMN notification_event_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY notifications_event_unique (notification_event_id),
    ADD CONSTRAINT notifications_event_fk FOREIGN KEY (notification_event_id) REFERENCES notification_events(id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119150002_link_delivered_notifications', '818712f3af8a58bf5db4b37964ab824cdf97ac5f4f4d39bcb6ae276978fb4eb4');

-- 20261119150003_create_email_queue
CREATE TABLE IF NOT EXISTS email_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_event_id BIGINT UNSIGNED NOT NULL UNIQUE,
    recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending','processing','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    last_result VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (status, available_at, id),
    FOREIGN KEY (notification_event_id) REFERENCES notification_events(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119150003_create_email_queue', '427b860406104e7d2d51dfbca22627a1598e4ee4733e1fdfd45f8cb57355afd1');

-- 20261119150004_link_email_delivery_attempts
ALTER TABLE email_logs
    ADD COLUMN queue_id BIGINT UNSIGNED NULL,
    ADD COLUMN attempt TINYINT UNSIGNED NULL,
    ADD UNIQUE KEY email_queue_attempt_unique (queue_id, attempt),
    ADD CONSTRAINT email_logs_queue_fk FOREIGN KEY (queue_id) REFERENCES email_queue(id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119150004_link_email_delivery_attempts', '5c207e711ed6f13475a2a2f17ac2a6b286ada7bfe36ff3aa9e951a1b0604510a');

-- 20261119160001_add_hr_export_permission
INSERT INTO permissions (code, description) VALUES
    ('hr_export_reports', 'Export organizational staff reports and analytics')
ON DUPLICATE KEY UPDATE description = permissions.description;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119160001_add_hr_export_permission', '24be3b988e10fd7c5ed9f3ee58963a5f2bb4d7b95dddf9ec7b5978538f939cd3');

-- 20261119160002_grant_hr_export_permission
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'hr_manager' AND p.code = 'hr_export_reports'
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119160002_grant_hr_export_permission', 'ae8dd3d0f0fe2126fec6d985e6adee932757c83cc131c29fe300a2bcf751b619');

-- 20261119160003_index_report_date_department
ALTER TABLE daily_reports ADD INDEX report_date_department (report_date, department_id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119160003_index_report_date_department', 'cfa21559b19c73fa728c9aababd10bcb6a51d14e203b8b691146326614730f64');

-- 20261119170001_distinguish_blocked_login_attempts
ALTER TABLE login_attempts ADD COLUMN blocked BOOLEAN NOT NULL DEFAULT FALSE;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119170001_distinguish_blocked_login_attempts', '88115d906beff0d8e9e736ecc649d4aeb9b346418b8dedee3ea5b537f7f27c0b');

-- 20261119170002_index_notification_history
CREATE INDEX notifications_user_history ON notifications (user_id, created_at DESC, id DESC);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119170002_index_notification_history', '7180475d796a60d0522b0dd56a44ed5aa0339ac888bd4f48bba33a77f5114322');

-- 20261119180001_index_staff_recent_reports
CREATE INDEX reports_staff_recent ON daily_reports (staff_id, updated_at DESC, id DESC);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119180001_index_staff_recent_reports', 'fc8b76b71bbb9907e2754c8a1693b1cd6b7a68134bbf029648b2a078047098f2');

-- 20261119190001_create_staff_work_schedules
CREATE TABLE staff_work_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    assignment_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status ENUM('assigned','cancelled') NOT NULL DEFAULT 'assigned',
    exception_reason VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_day TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'assigned' THEN day_of_week END) STORED,
    UNIQUE KEY schedule_assigned_day (user_id, effective_from, assigned_day),
    INDEX schedule_period_lookup (user_id, status, effective_from, effective_to),
    CHECK (day_of_week BETWEEN 1 AND 7),
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119190001_create_staff_work_schedules', '211f11f25a25deb610a79919b069645592f381a645af3fbccb1afc6b646f9545');

-- 20261119190002_record_work_schedule_calendar_baseline
INSERT INTO system_settings (setting_key, setting_value)
SELECT 'staff_schedule_calendar', JSON_OBJECT(
    'effective_from', DATE_FORMAT(UTC_DATE() + INTERVAL 1 DAY, '%Y-%m-%d'),
    'working_days', COALESCE(JSON_EXTRACT(setting_value, '$.working_days'), JSON_ARRAY(1, 2, 3, 4, 5))
)
FROM system_settings WHERE setting_key = 'operational_configuration';
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119190002_record_work_schedule_calendar_baseline', '69e39ea1b8b98b891e3437c8b4a78df580682484341b04213e3ab7195d2f52ce');

-- 20261119190003_guard_staff_schedule_overlap
DELIMITER $$
CREATE TRIGGER staff_work_schedules_guard_overlap
BEFORE INSERT ON staff_work_schedules FOR EACH ROW
BEGIN
    IF NEW.status = 'assigned' AND EXISTS (
        SELECT 1 FROM staff_work_schedules s
        WHERE s.user_id = NEW.user_id AND s.status = 'assigned' AND s.assignment_key <> NEW.assignment_key
            AND s.effective_from <= COALESCE(NEW.effective_to, '9999-12-31')
            AND (s.effective_to IS NULL OR s.effective_to >= NEW.effective_from)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The staff schedule overlaps an assigned period.';
    END IF;
END$$
DELIMITER ;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119190003_guard_staff_schedule_overlap', '2728941565a7ab68c9aba0e819227251715e6d19bc9fc4a3cb41f5e955f052c3');

-- 20261119200001_create_staff_leaves
CREATE TABLE staff_leaves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    leave_type ENUM('annual','sick','casual','parental','official_assignment','other') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NOT NULL,
    attachment_path VARCHAR(68) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    decision_note VARCHAR(500) NULL,
    overlap_reason VARCHAR(500) NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY leaves_attachment_path (attachment_path),
    INDEX leaves_staff_status_dates (user_id, status, start_date, end_date),
    INDEX leaves_status_dates (status, start_date, end_date, id),
    CHECK (end_date >= start_date),
    CHECK ((status IN ('approved','cancelled') AND approved_by IS NOT NULL AND approved_at IS NOT NULL)
        OR (status IN ('pending','rejected') AND approved_by IS NULL AND approved_at IS NULL)),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119200001_create_staff_leaves', 'a670296a96c4d793bfba11cdafc08b3c5984f84171613dbaaa77f22e11240aa6');

-- 20261119210001_create_work_holidays
CREATE TABLE work_holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    name VARCHAR(120) NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    department_scope BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(department_id, 0)) STORED,
    UNIQUE KEY holiday_date_scope (holiday_date, department_scope),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119210001_create_work_holidays', 'f861563e421b8ba3d64af385a11b7511d4012a6fc61d17b0fd206ca51b099c54');

-- 20261119220001_create_account_status_history
CREATE TABLE user_account_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    is_active BOOLEAN NOT NULL,
    changed_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX account_status_at (user_id, changed_at, id, is_active),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119220001_create_account_status_history', 'c46f7f57585beee78a1bd1c378ba42f8fe30bf4a06f66c8253a78ab1397acc27');

-- 20261119220002_backfill_account_status_history
INSERT INTO user_account_status_history (user_id, is_active, changed_at)
SELECT u.id, a.event = 'account_reactivated', a.created_at
FROM audit_logs a JOIN users u ON u.id = a.object_id
WHERE a.object_type = 'user' AND a.event IN ('account_deactivated','account_reactivated')
ORDER BY a.created_at, a.id;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119220002_backfill_account_status_history', 'ba921e6f241b3d462e61686d349a38b405a3552fd423ae38b54eded04bfc29f0');

-- 20261119220003_initialize_untracked_inactive_accounts
INSERT INTO user_account_status_history (user_id, is_active, changed_at)
SELECT u.id, FALSE, u.updated_at
FROM users u
WHERE u.is_active = FALSE AND NOT EXISTS (SELECT 1 FROM user_account_status_history h WHERE h.user_id = u.id);
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119220003_initialize_untracked_inactive_accounts', '059600cef7245cfcf6681c718f6582866a77b492235e23882290b1fcc199eecc');

-- 20261119220004_track_account_status_changes
DELIMITER $$
CREATE TRIGGER users_track_account_status
AFTER UPDATE ON users FOR EACH ROW
BEGIN
    IF OLD.is_active <> NEW.is_active THEN
        INSERT INTO user_account_status_history (user_id, is_active) VALUES (NEW.id, NEW.is_active);
    END IF;
END$$
DELIMITER ;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119220004_track_account_status_changes', '344cb4494442bcb1bbb5141f1ff568d2a1fb115cf7e94a5367fb54948d61922f');

-- 20261119230001_create_weekly_schedules
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
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119230001_create_weekly_schedules', '2015d477af13eb44b37873c666c2ca7f03783fd0fa9c2d0218f70c06536eb044');

-- 20261119230002_create_weekly_schedule_days
CREATE TABLE staff_weekly_schedule_days (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 weekly_schedule_id BIGINT UNSIGNED NOT NULL,
 scheduled_date DATE NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE (weekly_schedule_id,scheduled_date),
 INDEX (scheduled_date),
 FOREIGN KEY (weekly_schedule_id) REFERENCES staff_weekly_schedules(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119230002_create_weekly_schedule_days', 'af566d7d418636c91885164806c44700f56f47808e420f94cbb2d83363f0a770');

-- 20261119230003_create_weekly_results
CREATE TABLE staff_weekly_compliance_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 week_start DATE NOT NULL,
 final_result JSON NOT NULL,
 reason VARCHAR(500) NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX (user_id,week_start,id),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119230003_create_weekly_results', '398642f8342e45676c6a6c06f930b46bdd583ca4429ba73a42c8f97c44ae9288');

-- 20261119230004_activate_flexible_schedules
INSERT INTO system_settings (setting_key,setting_value) VALUES
 ('flexible_schedule_start', DATE_FORMAT(DATE_ADD(UTC_DATE(), INTERVAL (7-WEEKDAY(UTC_DATE())) DAY),'%Y-%m-%d'));
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119230004_activate_flexible_schedules', 'ae71ea76ad98751d7e8787c0988006dd1fbad2ac551fb48967ef4de55deab2db');

-- 20261119230005_scope_weekly_schedule_audit
CREATE SQL SECURITY DEFINER VIEW weekly_schedule_audit AS
SELECT id,actor_id,object_id,event,created_at,metadata
FROM audit_logs WHERE object_type='weekly_schedule';
INSERT INTO schema_migrations (version, checksum) VALUES ('20261119230005_scope_weekly_schedule_audit', '23b5dc1600b6393307b7bdced7029f73484bf8e9c9e49379646abb4203d918d0');

-- 20261120120001_remove_configurable_password_minimum
UPDATE system_settings
SET setting_value = JSON_REMOVE(setting_value, '$.password_minimum')
WHERE setting_key = 'operational_configuration'
  AND JSON_CONTAINS_PATH(setting_value, 'one', '$.password_minimum');
INSERT INTO schema_migrations (version, checksum) VALUES ('20261120120001_remove_configurable_password_minimum', 'aca1d5d54bb6856a8dafb3dfc0d12d57fba1626332a3b281e99176fcf3ee138d');

-- 20261120120002_repair_missing_schedule_calendar
INSERT INTO system_settings (setting_key, setting_value)
SELECT 'staff_schedule_calendar', JSON_OBJECT(
    'effective_from', DATE_FORMAT(m.applied_at + INTERVAL 1 DAY, '%Y-%m-%d'),
    'working_days', COALESCE(JSON_EXTRACT(s.setting_value, '$.working_days'), JSON_ARRAY(1, 2, 3, 4, 5))
)
FROM schema_migrations m
LEFT JOIN system_settings s ON s.setting_key = 'operational_configuration'
WHERE m.version = '20261119190002_record_work_schedule_calendar_baseline'
  AND NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'staff_schedule_calendar');
INSERT INTO schema_migrations (version, checksum) VALUES ('20261120120002_repair_missing_schedule_calendar', '5a1be28d95bc74403105b480e7f3688058b1df2764481e762ec7dcbc4a3b3c90');

-- Seed 01_roles.sql
INSERT INTO roles (code, name) VALUES
    ('staff', 'Staff'),
    ('supervisor', 'Supervisor'),
    ('hr_officer', 'HR Officer'),
    ('hr_manager', 'HR Manager'),
    ('system_admin', 'System Administrator')
ON DUPLICATE KEY UPDATE id = roles.id;

-- Seed 02_permissions.sql
INSERT INTO permissions (code, description) VALUES
    ('portal_access', 'Access the authenticated portal'),
    ('password_change', 'Change own password'),
    ('session_logout', 'End own session'),
    ('staff_workspace', 'Open staff workspace'),
    ('supervisor_workspace', 'Open supervisor workspace'),
    ('hr_officer_workspace', 'Open HR Officer workspace'),
    ('hr_manager_workspace', 'Open HR Manager workspace'),
    ('technical_workspace', 'Open technical administration workspace')
ON DUPLICATE KEY UPDATE id = permissions.id;

-- Seed 03_role_permissions.sql
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('portal_access', 'password_change', 'session_logout')
    OR (r.code = 'staff' AND p.code = 'staff_workspace')
    OR (r.code = 'supervisor' AND p.code = 'supervisor_workspace')
    OR (r.code = 'hr_officer' AND p.code = 'hr_officer_workspace')
    OR (r.code = 'hr_manager' AND p.code = 'hr_manager_workspace')
    OR (r.code = 'system_admin' AND p.code = 'technical_workspace')
WHERE r.code IN ('staff', 'supervisor', 'hr_officer', 'hr_manager', 'system_admin')
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;

-- Seed 04_installation_setting.sql
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('installation_initialized', '0')
ON DUPLICATE KEY UPDATE updated_at = system_settings.updated_at;

-- Seed 05_hr_manager_permissions.sql
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'hr_manager' AND p.code IN ('hr_manage_organization', 'hr_manage_officers')
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;

-- Seed 06_hr_review_permissions.sql
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE (r.code IN ('hr_officer','hr_manager') AND p.code = 'hr_review_reports')
    OR (r.code = 'hr_manager' AND p.code = 'hr_decide_reports')
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;

-- Seed 07_hr_export_permission.sql
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'hr_manager' AND p.code = 'hr_export_reports'
ON DUPLICATE KEY UPDATE created_at = role_permissions.created_at;
