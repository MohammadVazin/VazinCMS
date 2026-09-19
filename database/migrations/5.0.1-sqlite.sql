CREATE TABLE IF NOT EXISTS cms_extensions (id INTEGER PRIMARY KEY AUTOINCREMENT,extension_type TEXT NOT NULL,extension_key TEXT NOT NULL UNIQUE,version TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'active',settings_json TEXT NOT NULL DEFAULT '{}',installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS cms_starter_history (id INTEGER PRIMARY KEY AUTOINCREMENT,starter_key TEXT NOT NULL,version TEXT NOT NULL,applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
INSERT OR IGNORE INTO cms_settings(setting_key,setting_value) VALUES('active_theme','vazin-default');
