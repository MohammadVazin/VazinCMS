<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\MigrationSql;

$check = static function(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$empty = "-- comment-only migration\n-- second line\n  \n";
$check(!MigrationSql::containsStatements($empty), 'Comment-only migration must be no-op.');
$check(!MigrationSql::containsStatements(" \t\r\n"), 'Blank migration must not be executed.');
$check(MigrationSql::containsStatements("CREATE TABLE t (id integer);\n-- end"), 'DDL must execute.');
$check(MigrationSql::containsStatements("INSERT INTO t VALUES (1);"), 'DML must execute.');
$check(MigrationSql::containsStatements("SELECT '-- embedded';"), 'SQL with -- inside a string must not be skipped.');
$check(!MigrationSql::containsStatements(
    (string)file_get_contents(dirname(__DIR__) . '/database/migrations/10.30.1-pgsql.sql')
), 'Real 10.30.1 PostgreSQL migration is deliberately comment-only.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE schema_migrations(version TEXT PRIMARY KEY)');
$pdo->beginTransaction();
if (MigrationSql::containsStatements($empty)) $pdo->exec($empty);
$pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute(['test.noop.1']);
$pdo->commit();
$check((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===1,
    'No-op migration marker must be recorded transactionally.');

echo "Comment-only migration contract: OK\n";
