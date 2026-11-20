INSERT INTO system_settings (setting_key,setting_value) VALUES
 ('flexible_schedule_start', DATE_FORMAT(DATE_ADD(UTC_DATE(), INTERVAL (7-WEEKDAY(UTC_DATE())) DAY),'%Y-%m-%d'));
