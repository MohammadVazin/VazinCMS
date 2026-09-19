INSERT OR IGNORE INTO cms_settings(setting_key,setting_value) VALUES
 ('site_description',''),('logo_url',''),('contact_phone',''),('contact_address',''),('enabled_locales','["fa"]');
DELETE FROM cms_modules WHERE module_key IN ('travel','visa');
INSERT OR IGNORE INTO schema_migrations(version) VALUES('5.1.0');
