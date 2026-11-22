<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\{Database,SchemaHealth,SiteTenantSqliteUniqueMigrator};

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
    // A migration may deliberately contain only comments (for example
    // 10.30.1 is a webhook-contract-only release with no DDL). PDO_PGSQL
    // throws an error for a comment-only string; record the migration
    // version without calling exec() on non-executable input.
    // Do not skip files with any non-blank, non-line-comment content.
    $hasExecutableStatement = false;
    foreach (preg_split('/\\r\\n|\\r|\\n/', $sql) ?: [] as $line) {
        $line = ltrim($line);
        if ($line !== '' && !str_starts_with($line, '--')) {
            $hasExecutableStatement = true;
            break;
        }
    }
    if ($driver === 'sqlite' && $version === '10.31.1') {
        // Transactional rebuild of tenant-unique SQLite tables. This helper
        // owns BEGIN IMMEDIATE, FK verification, COMMIT and version marking;
        // the generic migrator must NOT surround it with another transaction.
        // Do not treat the marker-only SQL file as a completed migration.
        SiteTenantSqliteUniqueMigrator::apply($pdo,$version);
        echo "Migration {$version}: OK (SQLite tenant uniqueness)\n";
        continue;
    }
    $pdo->beginTransaction();
    try {
        if ($hasExecutableStatement) $pdo->exec($sql);
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
