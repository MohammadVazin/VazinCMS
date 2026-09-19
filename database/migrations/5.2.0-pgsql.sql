CREATE TABLE IF NOT EXISTS connector_nonces (nonce VARCHAR(128) PRIMARY KEY,expires_at TIMESTAMP NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_connector_nonces_expires ON connector_nonces(expires_at);
