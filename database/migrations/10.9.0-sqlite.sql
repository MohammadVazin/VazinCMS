CREATE TABLE IF NOT EXISTS telegram_assistant_profiles (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL UNIQUE REFERENCES telegram_connections(id) ON DELETE CASCADE,
 assistant_name TEXT NOT NULL,
 locale TEXT NOT NULL DEFAULT 'ru',
 timezone TEXT NOT NULL DEFAULT 'UTC',
 avatar_url TEXT NOT NULL DEFAULT '',
 welcome_encrypted TEXT,
 settings_json TEXT NOT NULL DEFAULT '{}',
 auto_reply_enabled INTEGER NOT NULL DEFAULT 0 CHECK(auto_reply_enabled IN (0,1)),
 booking_enabled INTEGER NOT NULL DEFAULT 0 CHECK(booking_enabled IN (0,1)),
 handoff_enabled INTEGER NOT NULL DEFAULT 1 CHECK(handoff_enabled IN (0,1)),
 is_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS telegram_business_connections (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 business_connection_id TEXT NOT NULL,
 business_user_id TEXT NOT NULL,
 user_chat_id TEXT NOT NULL,
 rights_json TEXT NOT NULL DEFAULT '{}',
 authorization_status TEXT NOT NULL DEFAULT 'pending' CHECK(authorization_status IN ('pending','authorized','rejected')),
 is_enabled INTEGER NOT NULL DEFAULT 0 CHECK(is_enabled IN (0,1)),
 connected_at TEXT NOT NULL,
 disabled_at TEXT,
 last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,business_connection_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_business_profile ON telegram_business_connections(profile_id,is_enabled,authorization_status);

CREATE TABLE IF NOT EXISTS telegram_assistant_contacts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 business_connection_row_id INTEGER NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 telegram_chat_id TEXT NOT NULL,
 telegram_user_id TEXT NOT NULL DEFAULT '',
 first_name TEXT NOT NULL DEFAULT '',
 last_name TEXT NOT NULL DEFAULT '',
 username TEXT NOT NULL DEFAULT '',
 language_code TEXT NOT NULL DEFAULT '',
 metadata_json TEXT NOT NULL DEFAULT '{}',
 is_blocked INTEGER NOT NULL DEFAULT 0 CHECK(is_blocked IN (0,1)),
 first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(business_connection_row_id,telegram_chat_id)
);

CREATE TABLE IF NOT EXISTS telegram_assistant_conversations (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 business_connection_row_id INTEGER NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 contact_id INTEGER NOT NULL REFERENCES telegram_assistant_contacts(id) ON DELETE CASCADE,
 telegram_chat_id TEXT NOT NULL,
 mode TEXT NOT NULL DEFAULT 'bot' CHECK(mode IN ('bot','handoff_pending','human','closed')),
 assigned_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
 unread_count INTEGER NOT NULL DEFAULT 0 CHECK(unread_count>=0),
 last_customer_at TEXT,
 last_operator_at TEXT,
 last_message_at TEXT,
 paused_until TEXT,
 lock_version INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(business_connection_row_id,telegram_chat_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_inbox ON telegram_assistant_conversations(profile_id,mode,last_message_at DESC);

CREATE TABLE IF NOT EXISTS telegram_assistant_messages (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 connection_id INTEGER NOT NULL REFERENCES telegram_connections(id) ON DELETE CASCADE,
 business_connection_row_id INTEGER NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 conversation_id INTEGER NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 telegram_update_id INTEGER,
 telegram_chat_id TEXT NOT NULL,
 telegram_message_id INTEGER NOT NULL,
 actor TEXT NOT NULL CHECK(actor IN ('customer','owner','bot','operator','external_bot','system')),
 direction TEXT NOT NULL CHECK(direction IN ('incoming','outgoing','system')),
 content_type TEXT NOT NULL DEFAULT 'text',
 content_encrypted TEXT NOT NULL,
 content_key TEXT NOT NULL,
 content_hash TEXT NOT NULL,
 metadata_json TEXT NOT NULL DEFAULT '{}',
 message_date TEXT NOT NULL,
 edited_at TEXT,
 deleted_at TEXT,
 expires_at TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(connection_id,business_connection_row_id,telegram_chat_id,telegram_message_id)
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_messages_conversation ON telegram_assistant_messages(conversation_id,message_date DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_messages_expiry ON telegram_assistant_messages(expires_at);

CREATE TABLE IF NOT EXISTS telegram_assistant_handoffs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 conversation_id INTEGER NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 reason_code TEXT NOT NULL DEFAULT 'customer_request',
 reason_encrypted TEXT,
 status TEXT NOT NULL DEFAULT 'requested' CHECK(status IN ('requested','accepted','resolved','cancelled')),
 assigned_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
 requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 accepted_at TEXT,
 resolved_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tg_handoff_open ON telegram_assistant_handoffs(conversation_id) WHERE status IN ('requested','accepted');

CREATE TABLE IF NOT EXISTS telegram_assistant_flow_sessions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 conversation_id INTEGER NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 flow_type TEXT NOT NULL,
 state TEXT NOT NULL,
 data_encrypted TEXT NOT NULL,
 lock_version INTEGER NOT NULL DEFAULT 0,
 expires_at TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(conversation_id,flow_type)
);
CREATE INDEX IF NOT EXISTS idx_tg_flow_expiry ON telegram_assistant_flow_sessions(expires_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_services (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 slug TEXT NOT NULL,
 title TEXT NOT NULL,
 summary TEXT NOT NULL DEFAULT '',
 duration_minutes INTEGER NOT NULL CHECK(duration_minutes BETWEEN 5 AND 1440),
 buffer_before_minutes INTEGER NOT NULL DEFAULT 0 CHECK(buffer_before_minutes BETWEEN 0 AND 1440),
 buffer_after_minutes INTEGER NOT NULL DEFAULT 0 CHECK(buffer_after_minutes BETWEEN 0 AND 1440),
 mode TEXT NOT NULL DEFAULT 'online' CHECK(mode IN ('online','in_person','both')),
 location_label TEXT NOT NULL DEFAULT '',
 price_amount NUMERIC,
 price_currency TEXT NOT NULL DEFAULT '',
 is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 position INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(profile_id,slug)
);

CREATE TABLE IF NOT EXISTS telegram_appointment_availability_rules (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 service_id INTEGER NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE CASCADE,
 weekday INTEGER NOT NULL CHECK(weekday BETWEEN 0 AND 6),
 start_time TEXT NOT NULL,
 end_time TEXT NOT NULL,
 slot_interval_minutes INTEGER NOT NULL CHECK(slot_interval_minutes BETWEEN 5 AND 1440),
 effective_from TEXT,
 effective_until TEXT,
 is_enabled INTEGER NOT NULL DEFAULT 1 CHECK(is_enabled IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_availability_rule ON telegram_appointment_availability_rules(service_id,weekday,is_enabled);

CREATE TABLE IF NOT EXISTS telegram_appointment_availability_exceptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 service_id INTEGER NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE CASCADE,
 starts_at TEXT NOT NULL,
 ends_at TEXT NOT NULL,
 kind TEXT NOT NULL CHECK(kind IN ('unavailable','available')),
 note TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(ends_at>starts_at)
);
CREATE INDEX IF NOT EXISTS idx_tg_availability_exception ON telegram_appointment_availability_exceptions(service_id,starts_at,ends_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_slots (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 service_id INTEGER NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE CASCADE,
 starts_at TEXT NOT NULL,
 ends_at TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'available' CHECK(status IN ('available','held','booked','blocked')),
 hold_expires_at TEXT,
 lock_version INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(ends_at>starts_at),
 UNIQUE(service_id,starts_at)
);
CREATE INDEX IF NOT EXISTS idx_tg_appointment_slots_available ON telegram_appointment_slots(service_id,status,starts_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_reservations (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 service_id INTEGER NOT NULL REFERENCES telegram_appointment_services(id) ON DELETE RESTRICT,
 slot_id INTEGER NOT NULL REFERENCES telegram_appointment_slots(id) ON DELETE RESTRICT,
 contact_id INTEGER REFERENCES telegram_assistant_contacts(id) ON DELETE SET NULL,
 conversation_id INTEGER REFERENCES telegram_assistant_conversations(id) ON DELETE SET NULL,
 public_ref TEXT NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'held' CHECK(status IN ('held','pending','confirmed','cancelled','expired','completed','no_show')),
 customer_timezone TEXT NOT NULL DEFAULT 'UTC',
 customer_encrypted TEXT NOT NULL,
 notes_encrypted TEXT,
 idempotency_key TEXT NOT NULL,
 confirmation_key TEXT,
 cancellation_key TEXT,
 hold_expires_at TEXT,
 confirmed_at TEXT,
 cancelled_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(profile_id,idempotency_key)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tg_reservation_active_slot ON telegram_appointment_reservations(slot_id) WHERE status IN ('held','pending','confirmed');
CREATE INDEX IF NOT EXISTS idx_tg_reservations_contact ON telegram_appointment_reservations(profile_id,contact_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tg_reservations_hold ON telegram_appointment_reservations(status,hold_expires_at);

CREATE TABLE IF NOT EXISTS telegram_appointment_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 reservation_id INTEGER NOT NULL REFERENCES telegram_appointment_reservations(id) ON DELETE CASCADE,
 event_type TEXT NOT NULL,
 actor_type TEXT NOT NULL DEFAULT 'system' CHECK(actor_type IN ('customer','operator','assistant','system')),
 actor_ref TEXT NOT NULL DEFAULT '',
 details_json TEXT NOT NULL DEFAULT '{}',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS telegram_assistant_outbox (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 business_connection_row_id INTEGER NOT NULL REFERENCES telegram_business_connections(id) ON DELETE CASCADE,
 conversation_id INTEGER NOT NULL REFERENCES telegram_assistant_conversations(id) ON DELETE CASCADE,
 telegram_chat_id TEXT NOT NULL,
 reply_to_message_id INTEGER,
 action TEXT NOT NULL CHECK(action IN ('send_message','edit_message','chat_action','callback_answer')),
 payload_encrypted TEXT NOT NULL,
 dedupe_key TEXT NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','sent','failed','cancelled')),
 attempts INTEGER NOT NULL DEFAULT 0,
 max_attempts INTEGER NOT NULL DEFAULT 5,
 next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_at TEXT,
 lock_token TEXT,
 telegram_message_id INTEGER,
 last_error TEXT,
 expires_at TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_outbox_due ON telegram_assistant_outbox(status,next_attempt_at,expires_at,id);

CREATE TABLE IF NOT EXISTS telegram_miniapp_sessions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 contact_id INTEGER REFERENCES telegram_assistant_contacts(id) ON DELETE SET NULL,
 token_hash TEXT NOT NULL UNIQUE,
 telegram_user_id TEXT NOT NULL,
 init_data_hash TEXT NOT NULL,
 data_encrypted TEXT NOT NULL,
 expires_at TEXT NOT NULL,
 last_used_at TEXT,
 revoked_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_miniapp_session_expiry ON telegram_miniapp_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_tg_miniapp_session_contact ON telegram_miniapp_sessions(profile_id,contact_id);

CREATE TABLE IF NOT EXISTS telegram_assistant_replays (
 replay_key TEXT PRIMARY KEY,
 profile_id INTEGER NOT NULL REFERENCES telegram_assistant_profiles(id) ON DELETE CASCADE,
 purpose TEXT NOT NULL,
 response_encrypted TEXT,
 expires_at TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_replay_expiry ON telegram_assistant_replays(expires_at);

CREATE TABLE IF NOT EXISTS telegram_assistant_rate_limits (
 rate_key TEXT PRIMARY KEY,
 hit_count INTEGER NOT NULL DEFAULT 0 CHECK(hit_count>=0),
 window_started_at TEXT NOT NULL,
 expires_at TEXT NOT NULL,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_assistant_rate_limit_expiry ON telegram_assistant_rate_limits(expires_at);

INSERT OR IGNORE INTO schema_migrations(version) VALUES('10.9.0');
