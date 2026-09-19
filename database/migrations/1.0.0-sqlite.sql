CREATE TABLE IF NOT EXISTS api_tokens (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name TEXT NOT NULL,
 token_prefix TEXT NOT NULL UNIQUE,
 token_hash TEXT NOT NULL UNIQUE,
 scopes TEXT NOT NULL DEFAULT '[]',
 created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 last_used_at TEXT,
 expires_at TEXT,
 revoked_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_api_tokens_active ON api_tokens(revoked_at,expires_at);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('1.0.0');
