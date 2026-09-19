ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS content_type VARCHAR(20) NOT NULL DEFAULT 'page';
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS published_at TIMESTAMP;
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS source_provider VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS source_ref VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS robots_index SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS robots_follow SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS og_title VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS og_description TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN IF NOT EXISTS schema_type VARCHAR(60) NOT NULL DEFAULT 'Article';
UPDATE cms_pages SET published_at=created_at WHERE status='published' AND published_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_cms_pages_source ON cms_pages(source_provider,source_ref) WHERE source_provider<>'' AND source_ref<>'';
CREATE INDEX IF NOT EXISTS idx_cms_pages_posts ON cms_pages(content_type,status,published_at DESC,id DESC);

CREATE TABLE IF NOT EXISTS identity_providers (
 provider VARCHAR(40) PRIMARY KEY,
 issuer_url TEXT NOT NULL DEFAULT '',
 client_id VARCHAR(255) NOT NULL DEFAULT '',
 scopes TEXT NOT NULL DEFAULT 'openid profile email',
 is_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 auto_provision SMALLINT NOT NULL DEFAULT 1 CHECK(auto_provision IN (0,1)),
 updated_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO identity_providers(provider) VALUES('vazin_id') ON CONFLICT(provider) DO NOTHING;

CREATE TABLE IF NOT EXISTS telegram_connections (
 id BIGSERIAL PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 bot_username VARCHAR(64) NOT NULL DEFAULT '',
 chat_id VARCHAR(80) NOT NULL UNIQUE,
 encrypted_bot_token TEXT NOT NULL,
 webhook_key CHAR(32) NOT NULL UNIQUE,
 encrypted_webhook_secret TEXT NOT NULL,
 sync_mode VARCHAR(24) NOT NULL DEFAULT 'telegram_to_site' CHECK(sync_mode IN ('telegram_to_site','site_to_telegram','bidirectional')),
 incoming_status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK(incoming_status IN ('draft','published')),
 locale VARCHAR(10) NOT NULL DEFAULT 'fa',
 auto_publish_site SMALLINT NOT NULL DEFAULT 0 CHECK(auto_publish_site IN (0,1)),
 manager_chat_id VARCHAR(80) NOT NULL DEFAULT '',
 web_app_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(web_app_enabled IN (0,1)),
 is_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 webhook_status VARCHAR(30) NOT NULL DEFAULT 'pending',
 last_update_id BIGINT,
 last_sync_at TIMESTAMP,
 last_error TEXT,
 created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_telegram_connections_enabled ON telegram_connections(is_enabled,sync_mode);

CREATE TABLE IF NOT EXISTS telegram_admins (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 telegram_user_id VARCHAR(80) NOT NULL,
 cms_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 is_enabled SMALLINT NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,telegram_user_id),
 UNIQUE(connection_id,cms_user_id)
);

CREATE TABLE IF NOT EXISTS telegram_updates (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 update_id BIGINT NOT NULL,
 update_type VARCHAR(40) NOT NULL,
 payload_json TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'received' CHECK(status IN ('received','processed','ignored','failed')),
 error_message TEXT,
 received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at TIMESTAMP,
 UNIQUE(connection_id,update_id)
);
CREATE INDEX IF NOT EXISTS idx_telegram_updates_status ON telegram_updates(connection_id,status,id);

CREATE TABLE IF NOT EXISTS telegram_messages (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 telegram_message_id BIGINT NOT NULL,
 telegram_update_id BIGINT,
 cms_page_id BIGINT REFERENCES cms_pages(id) ON DELETE SET NULL,
 direction VARCHAR(16) NOT NULL CHECK(direction IN ('incoming','outgoing')),
 status VARCHAR(20) NOT NULL DEFAULT 'synced' CHECK(status IN ('received','queued','synced','published','failed','deleted')),
 message_date TIMESTAMP NOT NULL,
 edit_date TIMESTAMP,
 content_hash CHAR(64) NOT NULL,
 payload_json TEXT NOT NULL DEFAULT '{}',
 last_error TEXT,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,telegram_message_id)
);
CREATE INDEX IF NOT EXISTS idx_telegram_messages_page ON telegram_messages(cms_page_id,direction);

CREATE TABLE IF NOT EXISTS telegram_outbox (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 cms_page_id BIGINT REFERENCES cms_pages(id) ON DELETE CASCADE,
 action VARCHAR(24) NOT NULL CHECK(action IN ('send_page','edit_page','manager_notice')),
 payload_json TEXT NOT NULL DEFAULT '{}',
 dedupe_key VARCHAR(190) NOT NULL UNIQUE,
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','sent','failed','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0,
 max_attempts INTEGER NOT NULL DEFAULT 5,
 next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_at TIMESTAMP,
 lock_token VARCHAR(64),
 telegram_message_id BIGINT,
 last_error TEXT,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_telegram_outbox_due ON telegram_outbox(status,next_attempt_at,id);

CREATE TABLE IF NOT EXISTS telegram_history_imports (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 source_type VARCHAR(24) NOT NULL CHECK(source_type IN ('telegram_export','mtproto')),
 source_fingerprint CHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'processing' CHECK(status IN ('processing','completed','partial','failed')),
 scanned_count INTEGER NOT NULL DEFAULT 0,
 created_count INTEGER NOT NULL DEFAULT 0,
 updated_count INTEGER NOT NULL DEFAULT 0,
 skipped_count INTEGER NOT NULL DEFAULT 0,
 error_count INTEGER NOT NULL DEFAULT 0,
 cursor_value TEXT,
 last_error TEXT,
 started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TIMESTAMP,
 UNIQUE(connection_id,source_type,source_fingerprint)
);

CREATE TABLE IF NOT EXISTS content_advisories (
 id BIGSERIAL PRIMARY KEY,
 page_id BIGINT NOT NULL REFERENCES cms_pages(id) ON DELETE CASCADE,
 rule_key VARCHAR(100) NOT NULL,
 severity VARCHAR(16) NOT NULL CHECK(severity IN ('info','warning','critical')),
 category VARCHAR(20) NOT NULL CHECK(category IN ('seo','content','medical','legal','publishing')),
 title VARCHAR(255) NOT NULL,
 message TEXT NOT NULL,
 details_json TEXT NOT NULL DEFAULT '{}',
 status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','acknowledged','resolved','ignored')),
 fingerprint CHAR(64) NOT NULL,
 first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at TIMESTAMP,
 UNIQUE(page_id,rule_key)
);
CREATE INDEX IF NOT EXISTS idx_content_advisories_open ON content_advisories(status,severity,last_seen_at DESC);

CREATE TABLE IF NOT EXISTS seo_reports (
 page_id BIGINT PRIMARY KEY REFERENCES cms_pages(id) ON DELETE CASCADE,
 score INTEGER NOT NULL CHECK(score BETWEEN 0 AND 100),
 content_hash CHAR(64) NOT NULL,
 issues_json TEXT NOT NULL DEFAULT '[]',
 analyzed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS editorial_suggestions (
 id BIGSERIAL PRIMARY KEY,
 suggestion_key VARCHAR(190) NOT NULL UNIQUE,
 title VARCHAR(255) NOT NULL,
 rationale TEXT NOT NULL,
 suggested_at TIMESTAMP NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','accepted','dismissed','completed')),
 details_json TEXT NOT NULL DEFAULT '{}',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO schema_migrations(version) VALUES('10.8.0') ON CONFLICT(version) DO NOTHING;
