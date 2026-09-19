CREATE TABLE IF NOT EXISTS travel_orders (
 id BIGSERIAL PRIMARY KEY, public_id VARCHAR(32) UNIQUE NOT NULL, access_hash VARCHAR(64) NOT NULL,
 locale VARCHAR(8) NOT NULL DEFAULT 'fa', service_type VARCHAR(32) NOT NULL, full_name VARCHAR(190) NOT NULL,
 email VARCHAR(190), phone VARCHAR(64) NOT NULL, destination VARCHAR(190), nationality VARCHAR(100),
 travelers INTEGER NOT NULL DEFAULT 1, travel_date DATE, notes TEXT, status VARCHAR(32) NOT NULL DEFAULT 'new',
 amount NUMERIC(18,2) NOT NULL DEFAULT 0, currency VARCHAR(8) NOT NULL DEFAULT 'RUB', payment_status VARCHAR(32) NOT NULL DEFAULT 'unpaid',
 payment_provider VARCHAR(32), payment_reference VARCHAR(190), created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHECK (travelers BETWEEN 1 AND 50)
);
CREATE INDEX IF NOT EXISTS idx_travel_orders_phone ON travel_orders(phone);
CREATE INDEX IF NOT EXISTS idx_travel_orders_status ON travel_orders(status,payment_status);
INSERT INTO schema_migrations(version) VALUES('5.0.0') ON CONFLICT(version) DO NOTHING;
