-- VazinCMS 10.10.0: white-label Travel/Visa catalog, protected eVisa intake,
-- provider contracts, agency leads, and consent-based Telegram alerts.

CREATE TABLE IF NOT EXISTS visa_products (
 id BIGSERIAL PRIMARY KEY,
 code VARCHAR(64) NOT NULL,
 locale VARCHAR(10) NOT NULL DEFAULT 'fa',
 title VARCHAR(255) NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 price NUMERIC(18,2) NOT NULL DEFAULT 0 CHECK(price>=0),
 currency VARCHAR(12) NOT NULL DEFAULT 'RUB',
 is_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 sort_order INTEGER NOT NULL DEFAULT 100,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(code,locale)
);
CREATE INDEX IF NOT EXISTS idx_visa_products_enabled ON visa_products(is_enabled,locale,sort_order,id);

CREATE TABLE IF NOT EXISTS visa_nationalities (
 code VARCHAR(16) PRIMARY KEY,
 name_fa VARCHAR(190) NOT NULL,
 name_ru VARCHAR(190) NOT NULL DEFAULT '',
 is_enabled SMALLINT NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 priority INTEGER NOT NULL DEFAULT 100,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_visa_nationalities_enabled ON visa_nationalities(is_enabled,priority,code);

CREATE TABLE IF NOT EXISTS visa_destinations (
 code VARCHAR(16) PRIMARY KEY,
 name_fa VARCHAR(190) NOT NULL,
 name_ru VARCHAR(190) NOT NULL DEFAULT '',
 is_enabled SMALLINT NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 priority INTEGER NOT NULL DEFAULT 100,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_visa_destinations_enabled ON visa_destinations(is_enabled,priority,code);

CREATE TABLE IF NOT EXISTS visa_rules (
 id BIGSERIAL PRIMARY KEY,
 nationality_code VARCHAR(16) NOT NULL,
 destination_code VARCHAR(16) NOT NULL,
 locale VARCHAR(10) NOT NULL DEFAULT 'fa',
 visa_type VARCHAR(120) NOT NULL,
 requirements TEXT NOT NULL DEFAULT '',
 documents TEXT NOT NULL DEFAULT '',
 processing_time VARCHAR(255) NOT NULL DEFAULT '',
 notes TEXT NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(nationality_code,destination_code,locale,visa_type)
);
CREATE INDEX IF NOT EXISTS idx_visa_rules_lookup ON visa_rules(nationality_code,locale,destination_code,visa_type);

CREATE TABLE IF NOT EXISTS travel_destinations (
 id BIGSERIAL PRIMARY KEY,
 audience_code VARCHAR(16) NOT NULL,
 locale VARCHAR(10) NOT NULL DEFAULT 'fa',
 country VARCHAR(190) NOT NULL,
 city VARCHAR(190) NOT NULL,
 summary TEXT NOT NULL DEFAULT '',
 entry_notes TEXT NOT NULL DEFAULT '',
 budget_notes TEXT NOT NULL DEFAULT '',
 local_tip TEXT NOT NULL DEFAULT '',
 priority INTEGER NOT NULL DEFAULT 100,
 is_enabled SMALLINT NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(audience_code,locale,country,city)
);
CREATE INDEX IF NOT EXISTS idx_travel_destinations_audience ON travel_destinations(audience_code,locale,is_enabled,priority,city);

-- Passport and application data are held only as sealed application payloads.
CREATE TABLE IF NOT EXISTS visa_application_intakes (
 id BIGSERIAL PRIMARY KEY,
 order_id BIGINT NOT NULL UNIQUE REFERENCES travel_orders(id) ON DELETE CASCADE,
 schema_version VARCHAR(32) NOT NULL DEFAULT '1',
 encrypted_payload TEXT NOT NULL,
 consent_version VARCHAR(64) NOT NULL DEFAULT 'visa-application-v1',
 consent_at TIMESTAMP NOT NULL,
 retention_until TIMESTAMP,
 completed_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_visa_application_intakes_retention ON visa_application_intakes(retention_until);

-- One CMS deployment represents one white-label tenant; credentials stay sealed.
CREATE TABLE IF NOT EXISTS travel_provider_connections (
 id BIGSERIAL PRIMARY KEY,
 provider_key VARCHAR(120) NOT NULL UNIQUE,
 label VARCHAR(190) NOT NULL,
 mode VARCHAR(32) NOT NULL DEFAULT 'lead_only' CHECK(mode IN ('lead_only','affiliate_redirect','manual_fulfilment','live_booking')),
 endpoint TEXT NOT NULL DEFAULT '',
 capabilities_json TEXT NOT NULL DEFAULT '[]',
 credentials_sealed TEXT,
 status VARCHAR(32) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','contract_pending','sandbox','active','paused')),
 created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_travel_provider_connections_status ON travel_provider_connections(status,mode,provider_key);

CREATE TABLE IF NOT EXISTS agency_inquiries (
 id BIGSERIAL PRIMARY KEY,
 organization VARCHAR(255) NOT NULL,
 contact_name VARCHAR(190) NOT NULL,
 phone VARCHAR(80) NOT NULL DEFAULT '',
 email VARCHAR(255) NOT NULL DEFAULT '',
 country VARCHAR(120) NOT NULL DEFAULT '',
 website TEXT NOT NULL DEFAULT '',
 locale VARCHAR(10) NOT NULL DEFAULT 'fa',
 services_json TEXT NOT NULL DEFAULT '[]',
 notes TEXT NOT NULL DEFAULT '',
 status VARCHAR(20) NOT NULL DEFAULT 'new' CHECK(status IN ('new','reviewing','qualified','closed')),
 assigned_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_agency_inquiries_queue ON agency_inquiries(status,created_at DESC,id DESC);

-- A customer or manager must opt in before this subsystem can address Telegram.
CREATE TABLE IF NOT EXISTS telegram_alert_subscriptions (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 recipient_type VARCHAR(16) NOT NULL CHECK(recipient_type IN ('manager','customer')),
 telegram_chat_id VARCHAR(80) NOT NULL DEFAULT '',
 telegram_user_id VARCHAR(80) NOT NULL DEFAULT '',
 source_type VARCHAR(40) NOT NULL DEFAULT 'site',
 source_id BIGINT NOT NULL DEFAULT 0,
 topics_json TEXT NOT NULL DEFAULT '[]',
 locale VARCHAR(10) NOT NULL DEFAULT 'fa',
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','opted_in','paused','revoked','blocked')),
 consent_version VARCHAR(64) NOT NULL DEFAULT 'telegram-alert-v1',
 consent_token_hash CHAR(64) UNIQUE,
 consent_expires_at TIMESTAMP,
 consented_at TIMESTAMP,
 revoked_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,recipient_type,source_type,source_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_alert_subscriptions_delivery ON telegram_alert_subscriptions(connection_id,status,source_type,source_id);
CREATE INDEX IF NOT EXISTS idx_tg_alert_subscriptions_chat ON telegram_alert_subscriptions(connection_id,telegram_chat_id,status);

-- Payloads are encrypted before enqueue; delivery is independently feature-gated.
CREATE TABLE IF NOT EXISTS telegram_alert_outbox (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 subscription_id BIGINT NOT NULL REFERENCES telegram_alert_subscriptions(id) ON DELETE CASCADE,
 source_type VARCHAR(40) NOT NULL DEFAULT 'site',
 source_id BIGINT NOT NULL DEFAULT 0,
 event_type VARCHAR(80) NOT NULL,
 dedupe_key VARCHAR(190) NOT NULL UNIQUE,
 payload_sealed TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','sent','failed','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts>=0),
 max_attempts INTEGER NOT NULL DEFAULT 5 CHECK(max_attempts BETWEEN 1 AND 20),
 next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_at TIMESTAMP,
 lock_token VARCHAR(64),
 last_error TEXT,
 telegram_message_id BIGINT,
 expires_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_alert_outbox_due ON telegram_alert_outbox(status,next_attempt_at,expires_at,id);
CREATE INDEX IF NOT EXISTS idx_tg_alert_outbox_subscription ON telegram_alert_outbox(subscription_id,status,created_at DESC);

INSERT INTO schema_migrations(version) VALUES('10.10.0') ON CONFLICT(version) DO NOTHING;
