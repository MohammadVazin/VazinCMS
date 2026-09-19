<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $driver = getenv('DB_CONNECTION') ?: 'pgsql';
        if ($driver === 'sqlite') {
            $dsn = 'sqlite:' . (getenv('DB_DATABASE') ?: RuntimePaths::storage() . '/database.sqlite');
        } else {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '5432', getenv('DB_DATABASE') ?: 'vazin_online');
        }
        self::$pdo = new PDO($dsn, getenv('DB_USERNAME') ?: null, getenv('DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($driver === 'sqlite') self::$pdo->exec('PRAGMA foreign_keys = ON');
        return self::$pdo;
    }
}
