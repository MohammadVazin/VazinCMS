CREATE TABLE IF NOT EXISTS webhook_endpoints (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, url TEXT NOT NULL,
 secret_hash TEXT NOT NULL, secret_encrypted TEXT NOT NULL, events TEXT NOT NULL,
 is_active INTEGER NOT NULL DEFAULT 1, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 last_success_at TEXT, last_failure_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS webhook_deliveries (
 id INTEGER PRIMARY KEY AUTOINCREMENT, endpoint_id INTEGER NOT NULL REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
 event_id TEXT NOT NULL UNIQUE, event_type TEXT NOT NULL, payload TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending', attempt_count INTEGER NOT NULL DEFAULT 0,
 response_status INTEGER, response_body TEXT, last_error TEXT, next_attempt_at TEXT,
 delivered_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_queue ON webhook_deliveries(status,next_attempt_at);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('1.2.0');
