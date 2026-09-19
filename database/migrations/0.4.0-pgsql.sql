CREATE TABLE IF NOT EXISTS invoices (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY, invoice_number VARCHAR(32) NOT NULL UNIQUE,
 order_id BIGINT UNIQUE REFERENCES orders(id) ON DELETE SET NULL, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 subtotal NUMERIC(18,2) NOT NULL CHECK(subtotal>=0), discount NUMERIC(18,2) NOT NULL DEFAULT 0 CHECK(discount>=0),
 total NUMERIC(18,2) NOT NULL CHECK(total>=0), currency VARCHAR(8) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'unpaid' CHECK(status IN ('unpaid','partial','paid','cancelled','refunded')),
 due_at TIMESTAMP, paid_at TIMESTAMP, notes TEXT, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_invoices_user ON invoices(user_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status,due_at);
CREATE TABLE IF NOT EXISTS payments (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY, invoice_id BIGINT NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT, amount NUMERIC(18,2) NOT NULL CHECK(amount>0), currency VARCHAR(8) NOT NULL,
 method VARCHAR(80) NOT NULL, reference VARCHAR(255), status VARCHAR(20) NOT NULL DEFAULT 'confirmed' CHECK(status IN ('pending','confirmed','failed','refunded')),
 received_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_created ON payments(created_at DESC);
INSERT INTO schema_migrations(version) VALUES('0.4.0') ON CONFLICT(version) DO NOTHING;
