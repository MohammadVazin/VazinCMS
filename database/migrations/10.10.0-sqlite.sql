-- VazinCMS 10.10.0: white-label Travel/Visa catalog, protected eVisa intake,
-- provider contracts, agency leads, and consent-based Telegram alerts.

CREATE TABLE IF NOT EXISTS visa_products (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 code TEXT NOT NULL,
 locale TEXT NOT NULL DEFAULT 'fa',
 title TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 price NUMERIC NOT NULL DEFAULT 0 CHECK(price>=0),
 currency TEXT NOT NULL DEFAULT 'RUB',
 is_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 sort_order INTEGER NOT NULL DEFAULT 100,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(code,locale)
);
CREATE INDEX IF NOT EXISTS idx_visa_products_enabled ON visa_products(is_enabled,locale,sort_order,id);

CREATE TABLE IF NOT EXISTS visa_nationalities (
 code TEXT PRIMARY KEY,
 name_fa TEXT NOT NULL,
 name_ru TEXT NOT NULL DEFAULT '',
 is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 priority INTEGER NOT NULL DEFAULT 100,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_visa_nationalities_enabled ON visa_nationalities(is_enabled,priority,code);

CREATE TABLE IF NOT EXISTS visa_destinations (
 code TEXT PRIMARY KEY,
 name_fa TEXT NOT NULL,
 name_ru TEXT NOT NULL DEFAULT '',
 is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 priority INTEGER NOT NULL DEFAULT 100,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_visa_destinations_enabled ON visa_destinations(is_enabled,priority,code);

CREATE TABLE IF NOT EXISTS visa_rules (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 nationality_code TEXT NOT NULL,
 destination_code TEXT NOT NULL,
 locale TEXT NOT NULL DEFAULT 'fa',
 visa_type TEXT NOT NULL,
 requirements TEXT NOT NULL DEFAULT '',
 documents TEXT NOT NULL DEFAULT '',
 processing_time TEXT NOT NULL DEFAULT '',
 notes TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(nationality_code,destination_code,locale,visa_type)
);
CREATE INDEX IF NOT EXISTS idx_visa_rules_lookup ON visa_rules(nationality_code,locale,destination_code,visa_type);

CREATE TABLE IF NOT EXISTS travel_destinations (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 audience_code TEXT NOT NULL,
 locale TEXT NOT NULL DEFAULT 'fa',
 country TEXT NOT NULL,
 city TEXT NOT NULL,
 summary TEXT NOT NULL DEFAULT '',
 entry_notes TEXT NOT NULL DEFAULT '',
 budget_notes TEXT NOT NULL DEFAULT '',
 local_tip TEXT NOT NULL DEFAULT '',
 priority INTEGER NOT NULL DEFAULT 100,
 is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(audience_code,locale,country,city)
);
CREATE INDEX IF NOT EXISTS idx_travel_destinations_audience ON travel_destinations(audience_code,locale,is_enabled,priority,city);

-- Passport and application data are held only as sealed application payloads.
CREATE TABLE IF NOT EXISTS visa_application_intakes (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_id INTEGER NOT NULL UNIQUE REFERENCES travel_orders(id) ON DELETE CASCADE,
 schema_version TEXT NOT NULL DEFAULT '1',
 encrypted_payload TEXT NOT NULL,
 consent_version TEXT NOT NULL DEFAULT 'visa-application-v1',
 consent_at TEXT NOT NULL,
 retention_until TEXT,
 completed_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_visa_application_intakes_retention ON visa_application_intakes(retention_until);

-- One CMS deployment represents one white-label tenant; credentials stay sealed.
CREATE TABLE IF NOT EXISTS travel_provider_connections (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 provider_key TEXT NOT NULL UNIQUE,
 label TEXT NOT NULL,
 mode TEXT NOT NULL DEFAULT 'lead_only' CHECK(mode IN ('lead_only','affiliate_redirect','manual_fulfilment','live_booking')),
 endpoint TEXT NOT NULL DEFAULT '',
 capabilities_json TEXT NOT NULL DEFAULT '[]',
 credentials_sealed TEXT,
 status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','contract_pending','sandbox','active','paused')),
 created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_travel_provider_connections_status ON travel_provider_connections(status,mode,provider_key);

CREATE TABLE IF NOT EXISTS agency_inquiries (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 organization TEXT NOT NULL,
 contact_name TEXT NOT NULL,
 phone TEXT NOT NULL DEFAULT '',
 email TEXT NOT NULL DEFAULT '',
 country TEXT NOT NULL DEFAULT '',
 website TEXT NOT NULL DEFAULT '',
 locale TEXT NOT NULL DEFAULT 'fa',
 services_json TEXT NOT NULL DEFAULT '[]',
 notes TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','reviewing','qualified','closed')),
 assigned_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_agency_inquiries_queue ON agency_inquiries(status,created_at DESC,id DESC);

-- A customer or manager must opt in before this subsystem can address Telegram.
CREATE TABLE IF NOT EXISTS telegram_alert_subscriptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 recipient_type TEXT NOT NULL CHECK(recipient_type IN ('manager','customer')),
 telegram_chat_id TEXT NOT NULL DEFAULT '',
 telegram_user_id TEXT NOT NULL DEFAULT '',
 source_type TEXT NOT NULL DEFAULT 'site',
 source_id INTEGER NOT NULL DEFAULT 0,
 topics_json TEXT NOT NULL DEFAULT '[]',
 locale TEXT NOT NULL DEFAULT 'fa',
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','opted_in','paused','revoked','blocked')),
 consent_version TEXT NOT NULL DEFAULT 'telegram-alert-v1',
 consent_token_hash TEXT UNIQUE,
 consent_expires_at TEXT,
 consented_at TEXT,
 revoked_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,recipient_type,source_type,source_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_alert_subscriptions_delivery ON telegram_alert_subscriptions(connection_id,status,source_type,source_id);
CREATE INDEX IF NOT EXISTS idx_tg_alert_subscriptions_chat ON telegram_alert_subscriptions(connection_id,telegram_chat_id,status);

-- Payloads are encrypted before enqueue; delivery is independently feature-gated.
CREATE TABLE IF NOT EXISTS telegram_alert_outbox (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 subscription_id INTEGER NOT NULL REFERENCES telegram_alert_subscriptions(id) ON DELETE CASCADE,
 source_type TEXT NOT NULL DEFAULT 'site',
 source_id INTEGER NOT NULL DEFAULT 0,
 event_type TEXT NOT NULL,
 dedupe_key TEXT NOT NULL UNIQUE,
 payload_sealed TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','sent','failed','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts>=0),
 max_attempts INTEGER NOT NULL DEFAULT 5 CHECK(max_attempts BETWEEN 1 AND 20),
 next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_at TEXT,
 lock_token TEXT,
 last_error TEXT,
 telegram_message_id INTEGER,
 expires_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_alert_outbox_due ON telegram_alert_outbox(status,next_attempt_at,expires_at,id);
CREATE INDEX IF NOT EXISTS idx_tg_alert_outbox_subscription ON telegram_alert_outbox(subscription_id,status,created_at DESC);

INSERT OR IGNORE INTO schema_migrations(version) VALUES('10.10.0');
