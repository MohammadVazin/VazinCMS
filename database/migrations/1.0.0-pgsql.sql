CREATE TABLE IF NOT EXISTS api_tokens (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
 name VARCHAR(160) NOT NULL,
 token_prefix VARCHAR(32) NOT NULL UNIQUE,
 token_hash VARCHAR(64) NOT NULL UNIQUE,
 scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
 created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 last_used_at TIMESTAMP,
 expires_at TIMESTAMP,
 revoked_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_api_tokens_active ON api_tokens(revoked_at,expires_at);
INSERT INTO schema_migrations(version) VALUES('1.0.0') ON CONFLICT(version) DO NOTHING;
