<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $cachedKey = null;

    public static function connection(): PDO
    {
        $configuredDriver = getenv('DB_CONNECTION');
        if ($configuredDriver !== false && trim($configuredDriver) !== '') {
            $driver = strtolower(trim($configuredDriver));
        } elseif (getenv('DB_HOST') !== false && trim((string) getenv('DB_HOST')) !== '') {
            $driver = 'pgsql';
        } else {
            $driver = 'sqlite';
        }

        $configuredDb = getenv('DB_DATABASE');
        $key = $driver . '|' . (string) $configuredDb . '|' . (string) getenv('DB_HOST') . '|' . (string) getenv('DB_PORT');
        if (self::$pdo instanceof PDO && self::$cachedKey === $key) {
            return self::$pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $isDefaultPath = ($configuredDb === false || trim($configuredDb) === '');
            $dbPath = !$isDefaultPath ? $configuredDb : (RuntimePaths::storage() . '/database.sqlite');
            if ($isDefaultPath && $dbPath !== ':memory:') {
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
            }
            $dsn = 'sqlite:' . $dbPath;
            self::$pdo = new PDO($dsn, null, null, $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$cachedKey = $key;
            return self::$pdo;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                $configuredDb ?: 'vazincms'
            );
            self::$pdo = new PDO($dsn, getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', $options);
            self::$cachedKey = $key;
            return self::$pdo;
        }

        if ($driver === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '5432',
                $configuredDb ?: 'vazincms'
            );
            self::$pdo = new PDO($dsn, getenv('DB_USERNAME') ?: null, getenv('DB_PASSWORD') ?: null, $options);
            self::$cachedKey = $key;
            return self::$pdo;
        }

        throw new RuntimeException('Unsupported database driver: ' . $driver);
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$cachedKey = null;
    }
}
