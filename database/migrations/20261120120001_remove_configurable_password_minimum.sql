UPDATE system_settings
SET setting_value = JSON_REMOVE(setting_value, '$.password_minimum')
WHERE setting_key = 'operational_configuration'
  AND JSON_CONTAINS_PATH(setting_value, 'one', '$.password_minimum');
