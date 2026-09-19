<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use PDO;
use VazinCMS\{Database,SchemaHealth};

$root = dirname(__DIR__);
$pdo = Database::connection();
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if (!in_array($driver, ['sqlite', 'pgsql'], true)) {
    throw new RuntimeException('Unsupported database driver: ' . $driver);
}
$exists = $driver === 'sqlite'
    ? (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn()
    : (bool) $pdo->query("SELECT to_regclass('public.schema_migrations')")->fetchColumn();
if (!$exists) {
    echo "Migrations: deferred until initial setup\n";
    exit(0);
}

$suffix = $driver === 'sqlite' ? 'sqlite' : 'pgsql';
$files = glob($root . '/database/migrations/*-' . $suffix . '.sql') ?: [];
usort($files, static fn(string $left, string $right): int => version_compare(
    explode('-', basename($left))[0], explode('-', basename($right))[0]
));

foreach ($files as $file) {
    $version = explode('-', basename($file))[0];
    if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        throw new RuntimeException('Invalid migration filename: ' . basename($file));
    }
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');
    $check->execute(['version' => $version]);
    if ($check->fetchColumn()) {
        echo "Migration {$version}: already applied\n";
        continue;
    }
    $sql = file_get_contents($file);
    if (!is_string($sql) || $sql === '') throw new RuntimeException('Migration unavailable: ' . $version);
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $mark = $pdo->prepare($driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO schema_migrations(version) VALUES(:version)'
            : 'INSERT INTO schema_migrations(version) VALUES(:version) ON CONFLICT(version) DO NOTHING');
        $mark->execute(['version' => $version]);
        $pdo->commit();
        echo "Migration {$version}: OK\n";
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

$health = SchemaHealth::repairAuthentication();
echo 'Authentication schema: ' . ($health['ok'] ? 'OK' : 'FAILED') . "\n";
