CREATE TABLE IF NOT EXISTS connector_nonces (nonce TEXT PRIMARY KEY,expires_at TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_connector_nonces_expires ON connector_nonces(expires_at);
