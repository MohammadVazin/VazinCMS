<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\Database;
use VazinCMS\RuntimePaths;

$name = (string) getenv('CMS_OWNER_NAME');
$email = strtolower((string) getenv('CMS_OWNER_EMAIL'));
$password = (string) getenv('CMS_OWNER_PASSWORD');

if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    throw new RuntimeException('Invalid CMS owner credentials');
}

$root = dirname(__DIR__);
$lock = RuntimePaths::storage() . '/installed.lock';
if (is_file($lock)) {
    echo "Already installed\n";
    exit(0);
}

$pdo = Database::connection();
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
    throw new RuntimeException('Unsupported database driver: ' . $driver);
}

$schema = $root . '/database/' . ($driver === 'sqlite' ? 'schema-sqlite.sql' : 'schema.sql');
$migrationSuffix = $driver === 'sqlite' ? 'sqlite' : 'pgsql';
$initialVersionSql = $driver === 'sqlite'
    ? "INSERT OR IGNORE INTO schema_migrations(version) VALUES('0.1.0')"
    : "INSERT INTO schema_migrations(version) VALUES('0.1.0') ON CONFLICT(version) DO NOTHING";

$pdo->beginTransaction();
try {
    $pdo->exec((string) file_get_contents($schema));

    $owner = $pdo->prepare(
        "INSERT INTO users(name,email,password_hash,role,status) VALUES(:name,:email,:password,'owner','active')"
    );
    $owner->execute([
        'name' => $name,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $pdo->exec($initialVersionSql);

    $files = glob($root . '/database/migrations/*-' . $migrationSuffix . '.sql') ?: [];
    usort(
        $files,
        static fn(string $a, string $b): int => version_compare(
            explode('-', basename($a))[0],
            explode('-', basename($b))[0]
        )
    );

    foreach ($files as $file) {
        $version = explode('-', basename($file))[0];
        $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');
        $check->execute(['version' => $version]);
        if (!$check->fetchColumn()) {
            $pdo->exec((string) file_get_contents($file));
            $mark = $pdo->prepare($driver === 'sqlite'
                ? 'INSERT OR IGNORE INTO schema_migrations(version) VALUES(:version)'
                : 'INSERT INTO schema_migrations(version) VALUES(:version) ON CONFLICT(version) DO NOTHING');
            $mark->execute(['version' => $version]);
        }
    }

    $pdo->commit();
    file_put_contents($lock, date(DATE_ATOM) . PHP_EOL, LOCK_EX);
    chmod($lock, 0640);
    echo "VazinCMS automated installation completed using {$driver}.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
