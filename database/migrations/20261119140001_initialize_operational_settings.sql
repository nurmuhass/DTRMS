INSERT INTO system_settings (setting_key, setting_value)
VALUES ('operational_configuration', '{}')
ON DUPLICATE KEY UPDATE setting_value = system_settings.setting_value;
