CREATE TABLE IF NOT EXISTS travel_orders (
 id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE NOT NULL, access_hash TEXT NOT NULL,
 locale TEXT NOT NULL DEFAULT 'fa', service_type TEXT NOT NULL, full_name TEXT NOT NULL,
 email TEXT, phone TEXT NOT NULL, destination TEXT, nationality TEXT, travelers INTEGER NOT NULL DEFAULT 1,
 travel_date TEXT, notes TEXT, status TEXT NOT NULL DEFAULT 'new', amount NUMERIC NOT NULL DEFAULT 0,
 currency TEXT NOT NULL DEFAULT 'RUB', payment_status TEXT NOT NULL DEFAULT 'unpaid', payment_provider TEXT,
 payment_reference TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK (travelers BETWEEN 1 AND 50)
);
CREATE INDEX IF NOT EXISTS idx_travel_orders_phone ON travel_orders(phone);
CREATE INDEX IF NOT EXISTS idx_travel_orders_status ON travel_orders(status,payment_status);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('5.0.0');
