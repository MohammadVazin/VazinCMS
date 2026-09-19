<?php
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('SESSION_SECURE=false');
require_once $root . '/src/bootstrap.php';

use VazinCMS\DatabaseTransaction;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expectFailure = static function (callable $operation, string $contains = ''): Throwable {
    try {
        $operation();
    } catch (Throwable $error) {
        if ($contains !== '' && !str_contains($error->getMessage(), $contains)) {
            throw new RuntimeException('Unexpected failure: '.$error->getMessage(), 0, $error);
        }
        return $error;
    }
    throw new RuntimeException('Expected transaction failure did not occur.');
};

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Database transaction contract: SKIPPED (pdo_sqlite unavailable)\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE events(id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');

$value = DatabaseTransaction::immediate($pdo, static function () use ($pdo): string {
    $pdo->exec("INSERT INTO events(value) VALUES('committed')");
    return 'result';
});
$expect($value === 'result' && (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === 1, 'SQLite transaction did not commit exactly once.');

$expected = $expectFailure(static fn() => DatabaseTransaction::immediate($pdo, static function () use ($pdo): void {
    $pdo->exec("INSERT INTO events(value) VALUES('rolled-back')");
    throw new DomainException('injected operation failure');
}), 'injected operation failure');
$expect($expected instanceof DomainException, 'Operation exception type was not preserved.');
$expect((int)$pdo->query("SELECT COUNT(*) FROM events WHERE value='rolled-back'")->fetchColumn() === 0, 'SQLite operation failure was not rolled back.');

$expectFailure(static fn() => DatabaseTransaction::immediate($pdo, static fn() => DatabaseTransaction::immediate($pdo, static fn() => null)), 'تو در تو');
$expect((int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === 1, 'Nested helper call changed committed data.');

$pdo->beginTransaction();
$expectFailure(static fn() => DatabaseTransaction::immediate($pdo, static fn() => null), 'از قبل');
$pdo->rollBack();

$pdo->exec('BEGIN IMMEDIATE');
$expectFailure(static fn() => DatabaseTransaction::immediate($pdo, static fn() => null));
$pdo->exec('ROLLBACK');

final class ContractPdo extends PDO
{
    public array $calls = [];
    public bool $active = false;
    public bool $failCommit = false;
    public bool $failCommitAfterSuccess = false;
    public bool $failRollback = false;

    public function __construct(private readonly string $driver)
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? $this->driver : null;
    }

    public function inTransaction(): bool
    {
        $this->calls[] = 'inTransaction';
        return $this->active;
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = 'beginTransaction';
        $this->active = true;
        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'commit';
        if ($this->failCommit) throw new PDOException('injected commit failure');
        $this->active = false;
        if ($this->failCommitAfterSuccess) throw new PDOException('injected post-commit transport failure');
        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'rollBack';
        if ($this->failRollback) throw new PDOException('injected rollback failure');
        if (!$this->active) throw new PDOException('There is no active transaction');
        $this->active = false;
        return true;
    }
}

$pgsql = new ContractPdo('pgsql');
$expect(DatabaseTransaction::immediate($pgsql, static fn() => 42) === 42, 'PostgreSQL callback result changed.');
$expect($pgsql->calls === ['inTransaction','beginTransaction','commit','inTransaction'], 'PostgreSQL begin/commit semantics changed: '.implode(',', $pgsql->calls));

$commitFailure = new ContractPdo('pgsql');
$commitFailure->failCommit = true;
$error = $expectFailure(static fn() => DatabaseTransaction::immediate($commitFailure, static fn() => null), 'injected commit failure');
$expect($error instanceof PDOException, 'Commit failure type was not preserved after successful rollback.');
$expect($commitFailure->calls === ['inTransaction','beginTransaction','commit','rollBack','inTransaction'], 'Commit failure did not execute one rollback.');

$ambiguous = new ContractPdo('pgsql');
$ambiguous->failCommit = true;
$ambiguous->failRollback = true;
$expectFailure(static fn() => DatabaseTransaction::immediate($ambiguous, static fn() => null), 'مبهم');
$before = count($ambiguous->calls);
$expectFailure(static fn() => DatabaseTransaction::immediate($ambiguous, static fn() => null), 'مبهم');
$expect(count($ambiguous->calls) === $before, 'Poisoned connection was touched again.');

$afterSuccess = new ContractPdo('pgsql');
$afterSuccess->failCommitAfterSuccess = true;
$expectFailure(static fn() => DatabaseTransaction::immediate($afterSuccess, static fn() => null), 'مبهم');
$afterSuccessCalls = count($afterSuccess->calls);
$expectFailure(static fn() => DatabaseTransaction::immediate($afterSuccess, static fn() => null), 'مبهم');
$expect(count($afterSuccess->calls) === $afterSuccessCalls, 'Commit-ambiguous connection was reused.');

$controller = (string)file_get_contents($root.'/src/Controllers/AuthController.php');
$transactionPosition = strpos($controller, 'DatabaseTransaction::immediate');
$loginPosition = strpos($controller, 'Auth::login', $transactionPosition === false ? 0 : $transactionPosition);
$expect($transactionPosition !== false && $loginPosition !== false && $transactionPosition < $loginPosition, 'Callback can create a login before transaction completion.');

echo "Database transaction contract: OK\n";
