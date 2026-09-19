CREATE TABLE IF NOT EXISTS active_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,session_hash TEXT NOT NULL UNIQUE,ip_address TEXT,user_agent TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,revoked_at TEXT);
CREATE INDEX IF NOT EXISTS idx_active_sessions_user ON active_sessions(user_id,last_seen_at DESC);
CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,attempt_key TEXT NOT NULL,email TEXT NOT NULL,ip_address TEXT,succeeded INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_login_limit ON login_attempts(attempt_key,created_at DESC);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('5.1.2');
