CREATE TABLE IF NOT EXISTS user_identities (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 provider TEXT NOT NULL,
 provider_subject TEXT NOT NULL,
 email TEXT,
 email_verified INTEGER NOT NULL DEFAULT 0,
 linked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at TEXT,
 UNIQUE(provider,provider_subject),
 UNIQUE(user_id,provider)
);
CREATE INDEX IF NOT EXISTS idx_user_identities_user ON user_identities(user_id);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('10.1.0');
