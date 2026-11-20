CREATE SQL SECURITY DEFINER VIEW weekly_schedule_audit AS
SELECT id,actor_id,object_id,event,created_at,metadata
FROM audit_logs WHERE object_type='weekly_schedule';
