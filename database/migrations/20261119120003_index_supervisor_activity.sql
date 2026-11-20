ALTER TABLE report_approvals ADD INDEX reviewer_stage_activity (reviewer_id, stage, decision, created_at);
