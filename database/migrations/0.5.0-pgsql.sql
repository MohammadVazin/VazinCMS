CREATE TABLE IF NOT EXISTS wallets (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 currency VARCHAR(8) NOT NULL, balance NUMERIC(18,2) NOT NULL DEFAULT 0 CHECK(balance>=0),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(user_id,currency)
);
CREATE INDEX IF NOT EXISTS idx_wallets_user ON wallets(user_id);
CREATE TABLE IF NOT EXISTS wallet_transactions (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY, wallet_id BIGINT NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT, type VARCHAR(20) NOT NULL CHECK(type IN ('credit','debit')),
 amount NUMERIC(18,2) NOT NULL CHECK(amount>0), balance_after NUMERIC(18,2) NOT NULL CHECK(balance_after>=0), currency VARCHAR(8) NOT NULL,
 reference VARCHAR(255), description TEXT NOT NULL, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wallet_transactions_wallet ON wallet_transactions(wallet_id,id DESC);
CREATE INDEX IF NOT EXISTS idx_wallet_transactions_user ON wallet_transactions(user_id,created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wallet_transactions_reference ON wallet_transactions(reference) WHERE reference IS NOT NULL;
INSERT INTO schema_migrations(version) VALUES('0.5.0') ON CONFLICT(version) DO NOTHING;
