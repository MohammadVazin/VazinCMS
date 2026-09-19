CREATE TABLE IF NOT EXISTS wallets (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),currency TEXT NOT NULL,balance REAL NOT NULL DEFAULT 0 CHECK(balance>=0),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(user_id,currency));
CREATE INDEX IF NOT EXISTS idx_wallets_user ON wallets(user_id);
CREATE TABLE IF NOT EXISTS wallet_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT,wallet_id INTEGER NOT NULL REFERENCES wallets(id),user_id INTEGER NOT NULL REFERENCES users(id),type TEXT NOT NULL CHECK(type IN ('credit','debit')),amount REAL NOT NULL CHECK(amount>0),balance_after REAL NOT NULL CHECK(balance_after>=0),currency TEXT NOT NULL,reference TEXT,description TEXT NOT NULL,created_by INTEGER REFERENCES users(id),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_wallet_transactions_wallet ON wallet_transactions(wallet_id,id DESC);
CREATE INDEX IF NOT EXISTS idx_wallet_transactions_user ON wallet_transactions(user_id,created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wallet_transactions_reference ON wallet_transactions(reference) WHERE reference IS NOT NULL;
INSERT OR IGNORE INTO schema_migrations(version) VALUES('0.5.0');
