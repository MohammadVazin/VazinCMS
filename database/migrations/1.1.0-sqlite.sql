CREATE TABLE IF NOT EXISTS api_request_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 token_id INTEGER REFERENCES api_tokens(id) ON DELETE SET NULL,
 method TEXT NOT NULL,
 path TEXT NOT NULL,
 status_code INTEGER NOT NULL,
 ip_address TEXT,
 duration_ms INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_api_request_logs_token_created ON api_request_logs(token_id,created_at DESC);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('1.1.0');
