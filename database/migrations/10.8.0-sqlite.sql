ALTER TABLE cms_pages ADD COLUMN content_type TEXT NOT NULL DEFAULT 'page' CHECK(content_type IN ('page','post'));
ALTER TABLE cms_pages ADD COLUMN published_at TEXT;
ALTER TABLE cms_pages ADD COLUMN source_provider TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN source_ref TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN robots_index INTEGER NOT NULL DEFAULT 1 CHECK(robots_index IN (0,1));
ALTER TABLE cms_pages ADD COLUMN robots_follow INTEGER NOT NULL DEFAULT 1 CHECK(robots_follow IN (0,1));
ALTER TABLE cms_pages ADD COLUMN og_title TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN og_description TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_pages ADD COLUMN schema_type TEXT NOT NULL DEFAULT 'Article';
UPDATE cms_pages SET published_at=created_at WHERE status='published' AND published_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_cms_pages_source ON cms_pages(source_provider,source_ref) WHERE source_provider<>'' AND source_ref<>'';
CREATE INDEX IF NOT EXISTS idx_cms_pages_posts ON cms_pages(content_type,status,published_at DESC,id DESC);

CREATE TABLE IF NOT EXISTS identity_providers (
 provider TEXT PRIMARY KEY,
 issuer_url TEXT NOT NULL DEFAULT '',
 client_id TEXT NOT NULL DEFAULT '',
 scopes TEXT NOT NULL DEFAULT 'openid profile email',
 is_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 auto_provision INTEGER NOT NULL DEFAULT 1 CHECK(auto_provision IN (0,1)),
 updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO identity_providers(provider) VALUES('vazin_id');

CREATE TABLE IF NOT EXISTS telegram_connections (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name TEXT NOT NULL,
 bot_username TEXT NOT NULL DEFAULT '',
 chat_id TEXT NOT NULL UNIQUE,
 encrypted_bot_token TEXT NOT NULL,
 webhook_key TEXT NOT NULL UNIQUE,
 encrypted_webhook_secret TEXT NOT NULL,
 sync_mode TEXT NOT NULL DEFAULT 'telegram_to_site' CHECK(sync_mode IN ('telegram_to_site','site_to_telegram','bidirectional')),
 incoming_status TEXT NOT NULL DEFAULT 'draft' CHECK(incoming_status IN ('draft','published')),
 locale TEXT NOT NULL DEFAULT 'fa',
 auto_publish_site INTEGER NOT NULL DEFAULT 0 CHECK(auto_publish_site IN (0,1)),
 manager_chat_id TEXT NOT NULL DEFAULT '',
 web_app_enabled INTEGER NOT NULL DEFAULT 0 CHECK(web_app_enabled IN (0,1)),
 is_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 webhook_status TEXT NOT NULL DEFAULT 'pending',
 last_update_id INTEGER,
 last_sync_at TEXT,
 last_error TEXT,
 created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_telegram_connections_enabled ON telegram_connections(is_enabled,sync_mode);

CREATE TABLE IF NOT EXISTS telegram_admins (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 telegram_user_id TEXT NOT NULL,
 cms_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,telegram_user_id),
 UNIQUE(connection_id,cms_user_id)
);

CREATE TABLE IF NOT EXISTS telegram_updates (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 update_id INTEGER NOT NULL,
 update_type TEXT NOT NULL,
 payload_json TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'received' CHECK(status IN ('received','processed','ignored','failed')),
 error_message TEXT,
 received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at TEXT,
 UNIQUE(connection_id,update_id)
);
CREATE INDEX IF NOT EXISTS idx_telegram_updates_status ON telegram_updates(connection_id,status,id);

CREATE TABLE IF NOT EXISTS telegram_messages (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 telegram_message_id INTEGER NOT NULL,
 telegram_update_id INTEGER,
 cms_page_id INTEGER REFERENCES cms_pages(id) ON DELETE SET NULL,
 direction TEXT NOT NULL CHECK(direction IN ('incoming','outgoing')),
 status TEXT NOT NULL DEFAULT 'synced' CHECK(status IN ('received','queued','synced','published','failed','deleted')),
 message_date TEXT NOT NULL,
 edit_date TEXT,
 content_hash TEXT NOT NULL,
 payload_json TEXT NOT NULL DEFAULT '{}',
 last_error TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,telegram_message_id)
);
CREATE INDEX IF NOT EXISTS idx_telegram_messages_page ON telegram_messages(cms_page_id,direction);

CREATE TABLE IF NOT EXISTS telegram_outbox (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 cms_page_id INTEGER REFERENCES cms_pages(id) ON DELETE CASCADE,
 action TEXT NOT NULL CHECK(action IN ('send_page','edit_page','manager_notice')),
 payload_json TEXT NOT NULL DEFAULT '{}',
 dedupe_key TEXT NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','sent','failed','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0,
 max_attempts INTEGER NOT NULL DEFAULT 5,
 next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_at TEXT,
 lock_token TEXT,
 telegram_message_id INTEGER,
 last_error TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_telegram_outbox_due ON telegram_outbox(status,next_attempt_at,id);

CREATE TABLE IF NOT EXISTS telegram_history_imports (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 source_type TEXT NOT NULL CHECK(source_type IN ('telegram_export','mtproto')),
 source_fingerprint TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'processing' CHECK(status IN ('processing','completed','partial','failed')),
 scanned_count INTEGER NOT NULL DEFAULT 0,
 created_count INTEGER NOT NULL DEFAULT 0,
 updated_count INTEGER NOT NULL DEFAULT 0,
 skipped_count INTEGER NOT NULL DEFAULT 0,
 error_count INTEGER NOT NULL DEFAULT 0,
 cursor_value TEXT,
 last_error TEXT,
 started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TEXT,
 UNIQUE(connection_id,source_type,source_fingerprint)
);

CREATE TABLE IF NOT EXISTS content_advisories (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 page_id INTEGER NOT NULL REFERENCES cms_pages(id) ON DELETE CASCADE,
 rule_key TEXT NOT NULL,
 severity TEXT NOT NULL CHECK(severity IN ('info','warning','critical')),
 category TEXT NOT NULL CHECK(category IN ('seo','content','medical','legal','publishing')),
 title TEXT NOT NULL,
 message TEXT NOT NULL,
 details_json TEXT NOT NULL DEFAULT '{}',
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','acknowledged','resolved','ignored')),
 fingerprint TEXT NOT NULL,
 first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at TEXT,
 UNIQUE(page_id,rule_key)
);
CREATE INDEX IF NOT EXISTS idx_content_advisories_open ON content_advisories(status,severity,last_seen_at DESC);

CREATE TABLE IF NOT EXISTS seo_reports (
 page_id INTEGER PRIMARY KEY REFERENCES cms_pages(id) ON DELETE CASCADE,
 score INTEGER NOT NULL CHECK(score BETWEEN 0 AND 100),
 content_hash TEXT NOT NULL,
 issues_json TEXT NOT NULL DEFAULT '[]',
 analyzed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS editorial_suggestions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 suggestion_key TEXT NOT NULL UNIQUE,
 title TEXT NOT NULL,
 rationale TEXT NOT NULL,
 suggested_at TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','accepted','dismissed','completed')),
 details_json TEXT NOT NULL DEFAULT '{}',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO schema_migrations(version) VALUES('10.8.0');
