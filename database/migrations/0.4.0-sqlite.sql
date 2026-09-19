CREATE TABLE IF NOT EXISTS invoices (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_number TEXT NOT NULL UNIQUE, order_id INTEGER UNIQUE REFERENCES orders(id) ON DELETE SET NULL,
 user_id INTEGER NOT NULL REFERENCES users(id), subtotal REAL NOT NULL CHECK(subtotal>=0), discount REAL NOT NULL DEFAULT 0 CHECK(discount>=0),
 total REAL NOT NULL CHECK(total>=0), currency TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'unpaid', due_at TEXT, paid_at TEXT,
 notes TEXT, created_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_invoices_user ON invoices(user_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status,due_at);
CREATE TABLE IF NOT EXISTS payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL REFERENCES invoices(id), user_id INTEGER NOT NULL REFERENCES users(id),
 amount REAL NOT NULL CHECK(amount>0), currency TEXT NOT NULL, method TEXT NOT NULL, reference TEXT, status TEXT NOT NULL DEFAULT 'confirmed',
 received_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_created ON payments(created_at DESC);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('0.4.0');
