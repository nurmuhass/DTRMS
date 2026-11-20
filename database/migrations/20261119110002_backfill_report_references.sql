UPDATE daily_reports
SET reference_number = CONCAT('DTR-LEGACY-', id), first_submitted_at = submitted_at
WHERE reference_number IS NULL;
