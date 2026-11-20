INSERT INTO system_settings (setting_key, setting_value)
VALUES ('installation_initialized', '0')
ON DUPLICATE KEY UPDATE updated_at = system_settings.updated_at;
