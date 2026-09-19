CREATE TABLE IF NOT EXISTS user_identities (
 id BIGSERIAL PRIMARY KEY,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 provider VARCHAR(40) NOT NULL,
 provider_subject VARCHAR(190) NOT NULL,
 email VARCHAR(255),
 email_verified SMALLINT NOT NULL DEFAULT 0,
 linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at TIMESTAMP,
 UNIQUE(provider,provider_subject),
 UNIQUE(user_id,provider)
);
CREATE INDEX IF NOT EXISTS idx_user_identities_user ON user_identities(user_id);
INSERT INTO schema_migrations(version) VALUES('10.1.0') ON CONFLICT(version) DO NOTHING;
