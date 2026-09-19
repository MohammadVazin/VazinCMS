CREATE TABLE IF NOT EXISTS telegram_assistant_profiles (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL UNIQUE REFERENCES telegram_connections(id) ON DELETE CASCADE,
 assistant_name VARCHAR(120) NOT NULL,
 locale VARCHAR(10) NOT NULL DEFAULT 'ru',
 timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
 avatar_url TEXT NOT NULL DEFAULT '',
 welcome_encrypted TEXT,
 settings_json TEXT NOT NULL DEFAULT '{}',
 auto_reply_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(auto_reply_enabled IN (0,1)),
 booking_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(booking_enabled IN (0,1)),
 handoff_enabled SMALLINT NOT NULL DEFAULT 1 CHECK(handoff_enabled IN (0,1)),
 is_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS telegram_business_connections (
 id BIGSERIAL PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 business_connection_id VARCHAR(255) NOT NULL,
 business_user_id VARCHAR(80) NOT NULL,
 user_chat_id VARCHAR(80) NOT NULL,
 rights_json TEXT NOT NULL DEFAULT '{}',
 authorization_status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(authorization_status IN ('pending','authorized','rejected')),
 is_enabled SMALLINT NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 connected_at TIMESTAMP NOT NULL,
 disabled_at TIMESTAMP,
 last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,business_connection_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_business_profile ON telegram_business_connections(profile_id,is_enabled,authorization_status);

CREATE TABLE IF NOT EXISTS telegram_assistant_contacts (
 id BIGSERIAL PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 business_connection_row_id BIGINT NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 telegram_chat_id VARCHAR(80) NOT NULL,
 telegram_user_id VARCHAR(80) NOT NULL DEFAULT '',
 first_name VARCHAR(255) NOT NULL DEFAULT '',
 last_name VARCHAR(255) NOT NULL DEFAULT '',
 username VARCHAR(255) NOT NULL DEFAULT '',
 language_code VARCHAR(32) NOT NULL DEFAULT '',
 metadata_json TEXT NOT NULL DEFAULT '{}',
 is_blocked SMALLINT NOT NULL DEFAULT 0 CHECK(is_blocked IN (0,1)),
 first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(business_connection_row_id,telegram_chat_id)
);

CREATE TABLE IF NOT EXISTS telegram_assistant_conversations (
 id BIGSERIAL PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 business_connection_row_id BIGINT NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 contact_id BIGINT NOT NULL REFERENCES telegram_assistant_contacts(id) ON DELETE CASCADE,
 telegram_chat_id VARCHAR(80) NOT NULL,
 mode VARCHAR(24) NOT NULL DEFAULT 'bot' CHECK(mode IN ('bot','handoff_pending','human','closed')),
 assigned_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
 unread_count INTEGER NOT NULL DEFAULT 0 CHECK(unread_count>=0),
 last_customer_at TIMESTAMP,
 last_operator_at TIMESTAMP,
 last_message_at TIMESTAMP,
 paused_until TIMESTAMP,
 lock_version INTEGER NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(business_connection_row_id,telegram_chat_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_inbox ON telegram_assistant_conversations(profile_id,mode,last_message_at DESC);

CREATE TABLE IF NOT EXISTS telegram_assistant_messages (
 id BIGSERIAL PRIMARY KEY,
 connection_id BIGINT NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 business_connection_row_id BIGINT NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 conversation_id BIGINT NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 telegram_update_id BIGINT,
 telegram_chat_id VARCHAR(80) NOT NULL,
 telegram_message_id BIGINT NOT NULL,
 actor VARCHAR(20) NOT NULL CHECK(actor IN ('customer','owner','bot','operator','external_bot','system')),
 direction VARCHAR(12) NOT NULL CHECK(direction IN ('incoming','outgoing','system')),
 content_type VARCHAR(24) NOT NULL DEFAULT 'text',
 content_encrypted TEXT NOT NULL,
 content_key CHAR(32) NOT NULL,
 content_hash CHAR(64) NOT NULL,
 metadata_json TEXT NOT NULL DEFAULT '{}',
 message_date TIMESTAMP NOT NULL,
 edited_at TIMESTAMP,
 deleted_at TIMESTAMP,
 expires_at TIMESTAMP NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,business_connection_row_id,telegram_chat_id,telegram_message_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_messages_conversation ON telegram_assistant_messages(conversation_id,message_date DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_messages_expiry ON telegram_assistant_messages(expires_at);

CREATE TABLE IF NOT EXISTS telegram_assistant_handoffs (
 id BIGSERIAL PRIMARY KEY,
 conversation_id BIGINT NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 reason_code VARCHAR(60) NOT NULL DEFAULT 'customer_request',
 reason_encrypted TEXT,
 status VARCHAR(20) NOT NULL DEFAULT 'requested' CHECK(status IN ('requested','accepted','resolved','cancelled')),
 assigned_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
 requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 accepted_at TIMESTAMP,
 resolved_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tg_handoff_open ON telegram_assistant_handoffs(conversation_id) WHERE status IN ('requested','accepted');

CREATE TABLE IF NOT EXISTS telegram_assistant_flow_sessions (
 id BIGSERIAL PRIMARY KEY,
 conversation_id BIGINT NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 flow_type VARCHAR(40) NOT NULL,
 state VARCHAR(60) NOT NULL,
 data_encrypted TEXT NOT NULL,
 lock_version INTEGER NOT NULL DEFAULT 0,
 expires_at TIMESTAMP NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(conversation_id,flow_type)
);
CREATE INDEX IF NOT EXISTS idx_tg_flow_expiry ON telegram_assistant_flow_sessions(expires_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_services (
 id BIGSERIAL PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 slug VARCHAR(120) NOT NULL,
 title VARCHAR(190) NOT NULL,
 summary TEXT NOT NULL DEFAULT '',
 duration_minutes INTEGER NOT NULL CHECK(duration_minutes BETWEEN 5 AND 1440),
 buffer_before_minutes INTEGER NOT NULL DEFAULT 0 CHECK(buffer_before_minutes BETWEEN 0 AND 1440),
 buffer_after_minutes INTEGER NOT NULL DEFAULT 0 CHECK(buffer_after_minutes BETWEEN 0 AND 1440),
 mode VARCHAR(20) NOT NULL DEFAULT 'online' CHECK(mode IN ('online','in_person','both')),
 location_label VARCHAR(255) NOT NULL DEFAULT '',
 price_amount NUMERIC(18,2),
 price_currency VARCHAR(12) NOT NULL DEFAULT '',
 is_enabled SMALLINT NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 position INTEGER NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(profile_id,slug)
);

CREATE TABLE IF NOT EXISTS telegram_appointment_availability_rules (
 id BIGSERIAL PRIMARY KEY,
 service_id BIGINT NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE CASCADE,
 weekday INTEGER NOT NULL CHECK(weekday BETWEEN 0 AND 6),
 start_time CHAR(5) NOT NULL,
 end_time CHAR(5) NOT NULL,
 slot_interval_minutes INTEGER NOT NULL CHECK(slot_interval_minutes BETWEEN 5 AND 1440),
 effective_from DATE,
 effective_until DATE,
 is_enabled SMALLINT NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_availability_rule ON telegram_appointment_availability_rules(service_id,weekday,is_enabled);

CREATE TABLE IF NOT EXISTS telegram_appointment_availability_exceptions (
 id BIGSERIAL PRIMARY KEY,
 service_id BIGINT NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE CASCADE,
 starts_at TIMESTAMP NOT NULL,
 ends_at TIMESTAMP NOT NULL,
 kind VARCHAR(16) NOT NULL CHECK(kind IN ('unavailable','available')),
 note VARCHAR(255) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(ends_at>starts_at)
);
CREATE INDEX IF NOT EXISTS idx_tg_availability_exception ON telegram_appointment_availability_exceptions(service_id,starts_at,ends_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_slots (
 id BIGSERIAL PRIMARY KEY,
 service_id BIGINT NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE CASCADE,
 starts_at TIMESTAMP NOT NULL,
 ends_at TIMESTAMP NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'available' CHECK(status IN ('available','held','booked','blocked')),
 hold_expires_at TIMESTAMP,
 lock_version INTEGER NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(ends_at>starts_at),
 UNIQUE(service_id,starts_at)
);
CREATE INDEX IF NOT EXISTS idx_tg_appointment_slots_available ON telegram_appointment_slots(service_id,status,starts_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_reservations (
 id BIGSERIAL PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 service_id BIGINT NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE RESTRICT,
 slot_id BIGINT NOT NULL REFERENCES telegram_appointment_slots(id) ON DELETE RESTRICT,
 contact_id BIGINT REFERENCES telegram_assistant_contacts(id) ON DELETE SET NULL,
 conversation_id BIGINT REFERENCES telegram_assistant_conversations(id) ON DELETE SET NULL,
 public_ref VARCHAR(40) NOT NULL UNIQUE,
 status VARCHAR(24) NOT NULL DEFAULT 'held' CHECK(status IN ('held','pending','confirmed','cancelled','expired','completed','no_show')),
 customer_timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
 customer_encrypted TEXT NOT NULL,
 notes_encrypted TEXT,
 idempotency_key CHAR(64) NOT NULL,
 confirmation_key CHAR(64),
 cancellation_key CHAR(64),
 hold_expires_at TIMESTAMP,
 confirmed_at TIMESTAMP,
 cancelled_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(profile_id,idempotency_key)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tg_reservation_active_slot ON telegram_appointment_reservations(slot_id) WHERE status IN ('held','pending','confirmed');
CREATE INDEX IF NOT EXISTS idx_tg_reservations_contact ON telegram_appointment_reservations(profile_id,contact_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tg_reservations_hold ON telegram_appointment_reservations(status,hold_expires_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_events (
 id BIGSERIAL PRIMARY KEY,
 reservation_id BIGINT NOT NULL REFERENCES telegram_appointment_reservations(id) ON DELETE CASCADE,
 event_type VARCHAR(40) NOT NULL,
 actor_type VARCHAR(20) NOT NULL DEFAULT 'system' CHECK(actor_type IN ('customer','operator','assistant','system')),
 actor_ref VARCHAR(80) NOT NULL DEFAULT '',
 details_json TEXT NOT NULL DEFAULT '{}',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS telegram_assistant_outbox (
 id BIGSERIAL PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 business_connection_row_id BIGINT NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 conversation_id BIGINT NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 telegram_chat_id VARCHAR(80) NOT NULL,
 reply_to_message_id BIGINT,
 action VARCHAR(24) NOT NULL CHECK(action IN ('send_message','edit_message','chat_action','callback_answer')),
 payload_encrypted TEXT NOT NULL,
 dedupe_key CHAR(64) NOT NULL UNIQUE,
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','sent','failed','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0,
 max_attempts INTEGER NOT NULL DEFAULT 5,
 next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_at TIMESTAMP,
 lock_token VARCHAR(64),
 telegram_message_id BIGINT,
 last_error TEXT,
 expires_at TIMESTAMP NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_outbox_due ON telegram_assistant_outbox(status,next_attempt_at,expires_at,id);

CREATE TABLE IF NOT EXISTS telegram_miniapp_sessions (
 id BIGSERIAL PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 contact_id BIGINT REFERENCES telegram_assistant_contacts(id) ON DELETE SET NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 telegram_user_id VARCHAR(80) NOT NULL,
 init_data_hash CHAR(64) NOT NULL,
 data_encrypted TEXT NOT NULL,
 expires_at TIMESTAMP NOT NULL,
 last_used_at TIMESTAMP,
 revoked_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_miniapp_session_expiry ON telegram_miniapp_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_tg_miniapp_session_contact ON telegram_miniapp_sessions(profile_id,contact_id);

CREATE TABLE IF NOT EXISTS telegram_assistant_replays (
 replay_key CHAR(64) PRIMARY KEY,
 profile_id BIGINT NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 purpose VARCHAR(40) NOT NULL,
 response_encrypted TEXT,
 expires_at TIMESTAMP NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_replay_expiry ON telegram_assistant_replays(expires_at);

CREATE TABLE IF NOT EXISTS telegram_assistant_rate_limits (
 rate_key VARCHAR(190) PRIMARY KEY,
 hit_count INTEGER NOT NULL DEFAULT 0 CHECK(hit_count>=0),
 window_started_at TIMESTAMP NOT NULL,
 expires_at TIMESTAMP NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_rate_limit_expiry ON telegram_assistant_rate_limits(expires_at);

INSERT INTO schema_migrations(version) VALUES('10.9.0') ON CONFLICT(version) DO NOTHING;
