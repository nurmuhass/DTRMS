CREATE INDEX reports_staff_recent ON daily_reports (staff_id, updated_at DESC, id DESC);
