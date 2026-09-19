CREATE TABLE IF NOT EXISTS visa_case_events (
 id BIGSERIAL PRIMARY KEY,order_id BIGINT NOT NULL REFERENCES travel_orders(id) ON DELETE CASCADE,
 event_type VARCHAR(32) NOT NULL,old_status VARCHAR(32),new_status VARCHAR(32),message TEXT,
 visibility VARCHAR(16) NOT NULL DEFAULT 'customer' CHECK(visibility IN ('customer','internal')),
 actor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS visa_case_documents (
 id BIGSERIAL PRIMARY KEY,order_id BIGINT NOT NULL REFERENCES travel_orders(id) ON DELETE CASCADE,
 document_type VARCHAR(64) NOT NULL DEFAULT 'other',original_name VARCHAR(255) NOT NULL,stored_name VARCHAR(190) NOT NULL UNIQUE,
 mime_type VARCHAR(100) NOT NULL,file_size BIGINT NOT NULL CHECK(file_size BETWEEN 1 AND 15728640),sha256 CHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','accepted','rejected')),
 reviewer_note TEXT,uploaded_by VARCHAR(16) NOT NULL DEFAULT 'customer' CHECK(uploaded_by IN ('customer','admin')),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_visa_case_events_order ON visa_case_events(order_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_visa_case_documents_order ON visa_case_documents(order_id,status,created_at DESC);
INSERT INTO schema_migrations(version) VALUES('10.5.0') ON CONFLICT(version) DO NOTHING;
