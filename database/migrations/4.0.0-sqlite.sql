UPDATE module_instances SET version='6.0.2',status='healthy',base_url='https://travel.vazin.online',last_seen_at=CURRENT_TIMESTAMP,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE module_key='vazincms-travel';
INSERT OR IGNORE INTO schema_migrations(version) VALUES('4.0.0');
