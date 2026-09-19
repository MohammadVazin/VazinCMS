ALTER TABLE cms_extensions RENAME TO cms_extensions_legacy_1070;
CREATE TABLE cms_extensions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 extension_type TEXT NOT NULL CHECK(extension_type IN ('theme','module')),
 extension_key TEXT NOT NULL UNIQUE,
 name TEXT NOT NULL,
 version TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'inactive' CHECK(status IN ('inactive','active','broken','removed')),
 source TEXT NOT NULL DEFAULT 'runtime' CHECK(source IN ('bundled','runtime','legacy')),
 package_path TEXT NOT NULL DEFAULT '',
 checksum TEXT NOT NULL DEFAULT '',
 manifest_json TEXT NOT NULL DEFAULT '{}',
 last_error TEXT,
 installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 activated_at TEXT,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO cms_extensions(extension_type,extension_key,name,version,status,source,installed_at,updated_at)
SELECT CASE WHEN extension_type='theme' THEN 'theme' ELSE 'module' END,
       extension_key,extension_key,version,
       CASE WHEN status='active' THEN 'active' ELSE 'inactive' END,
       'legacy',installed_at,installed_at
FROM cms_extensions_legacy_1070;
DROP TABLE cms_extensions_legacy_1070;
CREATE INDEX idx_cms_extensions_type_status ON cms_extensions(extension_type,status,extension_key);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('10.7.0');
