INSERT INTO system_settings (setting_key, setting_value)
SELECT 'staff_schedule_calendar', JSON_OBJECT(
    'effective_from', DATE_FORMAT(m.applied_at + INTERVAL 1 DAY, '%Y-%m-%d'),
    'working_days', COALESCE(JSON_EXTRACT(s.setting_value, '$.working_days'), JSON_ARRAY(1, 2, 3, 4, 5))
)
FROM schema_migrations m
LEFT JOIN system_settings s ON s.setting_key = 'operational_configuration'
WHERE m.version = '20261119190002_record_work_schedule_calendar_baseline'
  AND NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'staff_schedule_calendar');
