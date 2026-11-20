CREATE INDEX notifications_user_history ON notifications (user_id, created_at DESC, id DESC);
