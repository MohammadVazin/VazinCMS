CREATE TABLE IF NOT EXISTS discounts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 code TEXT NOT NULL UNIQUE,
 type TEXT NOT NULL CHECK(type IN ('percent','fixed')),
 value NUMERIC NOT NULL CHECK(value>0), minimum_amount NUMERIC NOT NULL DEFAULT 0,
 currency TEXT NULL, max_uses INTEGER NULL, used_count INTEGER NOT NULL DEFAULT 0,
 starts_at TEXT NULL, expires_at TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS discount_redemptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, discount_id INTEGER NOT NULL REFERENCES discounts(id),
 order_id INTEGER NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
 user_id INTEGER NOT NULL REFERENCES users(id), code TEXT NOT NULL,
 discount_amount NUMERIC NOT NULL, currency TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE orders ADD COLUMN subtotal NUMERIC;
ALTER TABLE orders ADD COLUMN discount_amount NUMERIC NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN discount_id INTEGER REFERENCES discounts(id);
ALTER TABLE orders ADD COLUMN discount_code TEXT;
UPDATE orders SET subtotal=amount WHERE subtotal IS NULL;
CREATE INDEX IF NOT EXISTS idx_discounts_active ON discounts(is_active,expires_at);
CREATE INDEX IF NOT EXISTS idx_discount_redemptions_user ON discount_redemptions(user_id,created_at DESC);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('0.8.0');
