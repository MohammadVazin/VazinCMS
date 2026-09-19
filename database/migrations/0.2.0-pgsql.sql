ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(40);
ALTER TABLE users ADD COLUMN IF NOT EXISTS company VARCHAR(160);
ALTER TABLE users ADD COLUMN IF NOT EXISTS country VARCHAR(80);
ALTER TABLE users ADD COLUMN IF NOT EXISTS notes TEXT;
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check;
ALTER TABLE users ADD CONSTRAINT users_status_check CHECK(status IN ('active','suspended','pending','closed'));
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);
CREATE INDEX IF NOT EXISTS idx_users_created ON users(created_at DESC);
CREATE TABLE IF NOT EXISTS tickets (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY, user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
 subject VARCHAR(240) NOT NULL, department VARCHAR(40) NOT NULL DEFAULT 'support', priority VARCHAR(20) NOT NULL DEFAULT 'normal' CHECK(priority IN ('low','normal','high','urgent')),
 status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','answered','customer_reply','closed')),
 assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status,updated_at DESC);
CREATE TABLE IF NOT EXISTS ticket_messages (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY, ticket_id BIGINT NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
 author_id INTEGER REFERENCES users(id) ON DELETE SET NULL, body TEXT NOT NULL, is_internal SMALLINT NOT NULL DEFAULT 0 CHECK(is_internal IN (0,1)), created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ticket_messages ON ticket_messages(ticket_id,created_at);
INSERT INTO schema_migrations(version) VALUES('0.2.0') ON CONFLICT(version) DO NOTHING;
