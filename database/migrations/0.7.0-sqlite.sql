CREATE TABLE IF NOT EXISTS notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 channel TEXT NOT NULL DEFAULT 'email', event_type TEXT NOT NULL,
 dedupe_key TEXT NOT NULL UNIQUE, subject TEXT NOT NULL, body TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending', scheduled_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 sent_at TEXT NULL, failed_at TEXT NULL, attempts INTEGER NOT NULL DEFAULT 0,
 last_error TEXT NULL, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notifications_queue ON notifications(status,scheduled_at,id);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id,created_at DESC);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('0.7.0');
