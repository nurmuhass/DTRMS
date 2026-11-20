INSERT INTO system_settings (setting_key, setting_value)
SELECT 'staff_schedule_calendar', JSON_OBJECT(
    'effective_from', DATE_FORMAT(UTC_DATE() + INTERVAL 1 DAY, '%Y-%m-%d'),
    'working_days', COALESCE(JSON_EXTRACT(setting_value, '$.working_days'), JSON_ARRAY(1, 2, 3, 4, 5))
)
FROM system_settings WHERE setting_key = 'operational_configuration';
