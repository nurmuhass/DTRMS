ALTER TABLE notifications
    ADD COLUMN notification_event_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY notifications_event_unique (notification_event_id),
    ADD CONSTRAINT notifications_event_fk FOREIGN KEY (notification_event_id) REFERENCES notification_events(id);
