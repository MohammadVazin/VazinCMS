INSERT INTO cms_settings(setting_key,setting_value) VALUES
 ('site_description',''),('logo_url',''),('contact_phone',''),('contact_address',''),('enabled_locales','["fa"]') ON CONFLICT(setting_key) DO NOTHING;
DELETE FROM cms_modules WHERE module_key IN ('travel','visa');
INSERT INTO schema_migrations(version) VALUES('5.1.0') ON CONFLICT(version) DO NOTHING;
