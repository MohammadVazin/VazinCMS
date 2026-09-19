<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;

final class SchemaHealth
{
    public static function repairAuthentication(): array
    {
        $pdo=Database::connection();$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$repairs=[];
        if($driver==='sqlite'){
            $columns=$pdo->query("PRAGMA table_info(users)")->fetchAll();$names=array_column($columns,'name');
            if(!in_array('session_version',$names,true)){$pdo->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1');$repairs[]='users.session_version';}
            $pdo->exec("CREATE TABLE IF NOT EXISTS active_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,session_hash TEXT NOT NULL UNIQUE,ip_address TEXT,user_agent TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,revoked_at TEXT)");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_active_sessions_user ON active_sessions(user_id,last_seen_at DESC)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,attempt_key TEXT NOT NULL,email TEXT NOT NULL,ip_address TEXT,succeeded INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_limit ON login_attempts(attempt_key,created_at DESC)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS connector_nonces (nonce TEXT PRIMARY KEY,expires_at TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        }else{
            $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS session_version INTEGER NOT NULL DEFAULT 1');
            $pdo->exec('CREATE TABLE IF NOT EXISTS active_sessions (id BIGSERIAL PRIMARY KEY,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,session_hash CHAR(64) NOT NULL UNIQUE,ip_address VARCHAR(64),user_agent TEXT,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,revoked_at TIMESTAMP)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_active_sessions_user ON active_sessions(user_id,last_seen_at DESC)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (id BIGSERIAL PRIMARY KEY,attempt_key CHAR(64) NOT NULL,email VARCHAR(190) NOT NULL,ip_address VARCHAR(64),succeeded SMALLINT NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_limit ON login_attempts(attempt_key,created_at DESC)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS connector_nonces (nonce VARCHAR(128) PRIMARY KEY,expires_at TIMESTAMP NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        }
        $pdo->query('SELECT id,session_version FROM users LIMIT 1');$pdo->query('SELECT id FROM active_sessions LIMIT 1');$pdo->query('SELECT id FROM login_attempts LIMIT 1');$pdo->query('SELECT nonce FROM connector_nonces LIMIT 1');
        return ['ok'=>true,'repairs'=>$repairs,'database'=>$driver];
    }
}
