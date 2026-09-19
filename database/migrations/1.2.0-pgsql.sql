CREATE TABLE IF NOT EXISTS webhook_endpoints (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
 name VARCHAR(120) NOT NULL, url VARCHAR(2048) NOT NULL,
 secret_hash CHAR(64) NOT NULL, secret_encrypted TEXT NOT NULL, events TEXT NOT NULL,
 is_active SMALLINT NOT NULL DEFAULT 1, created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
 last_success_at TIMESTAMP, last_failure_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS webhook_deliveries (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY, endpoint_id BIGINT NOT NULL REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
 event_id VARCHAR(64) NOT NULL UNIQUE, event_type VARCHAR(80) NOT NULL, payload TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending', attempt_count INTEGER NOT NULL DEFAULT 0,
 response_status INTEGER, response_body TEXT, last_error TEXT, next_attempt_at TIMESTAMP,
 delivered_at TIMESTAMP, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_queue ON webhook_deliveries(status,next_attempt_at);
INSERT INTO schema_migrations(version) VALUES('1.2.0') ON CONFLICT(version) DO NOTHING;
