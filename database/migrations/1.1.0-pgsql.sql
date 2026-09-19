CREATE TABLE IF NOT EXISTS api_request_logs (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
 token_id BIGINT REFERENCES api_tokens(id) ON DELETE SET NULL,
 method VARCHAR(10) NOT NULL,
 path VARCHAR(255) NOT NULL,
 status_code INTEGER NOT NULL,
 ip_address VARCHAR(64),
 duration_ms INTEGER NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_api_request_logs_token_created ON api_request_logs(token_id,created_at DESC);
INSERT INTO schema_migrations(version) VALUES('1.1.0') ON CONFLICT(version) DO NOTHING;
