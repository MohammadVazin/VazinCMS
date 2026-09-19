<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * One transaction model for callback identity writes.
 *
 * PDO 8.2 does not necessarily report a raw SQLite BEGIN IMMEDIATE through
 * inTransaction(), so SQLite completion also uses raw COMMIT/ROLLBACK. The
 * process-local lease rejects nested helper calls, while SQLite itself rejects
 * an untracked transaction opened by another caller.
 */
final class DatabaseTransaction
{
    private static ?WeakMap $leases = null;
    private static ?WeakMap $poisoned = null;

    private string $state = 'idle';

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $driver,
    ) {
    }

    public static function immediate(PDO $pdo, callable $operation): mixed
    {
        $driver = self::driver($pdo);
        $transaction = new self($pdo, $driver);
        $transaction->begin();
        try {
            $result = $operation();
        } catch (Throwable $error) {
            $transaction->rollbackOrPoison($error);
            throw $error;
        }

        try {
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollbackOrPoison($error);
            throw $error;
        }
        return $result;
    }

    /** True only while this helper owns the process-local PDO transaction. */
    public static function owns(PDO $pdo): bool
    {
        return isset(self::leases()[$pdo]);
    }

    private function begin(): void
    {
        $leases = self::leases();
        if (isset(self::poisoned()[$this->pdo])) {
            throw new RuntimeException('اتصال پایگاه داده پس از وضعیت مبهم قابل استفاده نیست.');
        }
        if (isset($leases[$this->pdo])) {
            throw new RuntimeException('تراکنش تو در تو مجاز نیست.');
        }
        try {
            if ($this->pdo->inTransaction()) {
                throw new RuntimeException('اتصال پایگاه داده از قبل در تراکنش است.');
            }
            if ($this->driver === 'sqlite') {
                if ($this->pdo->exec('BEGIN IMMEDIATE') === false) {
                    throw new RuntimeException('تراکنش فوری SQLite آغاز نشد.');
                }
            } elseif (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('تراکنش PostgreSQL آغاز نشد.');
            }
        } catch (Throwable $error) {
            $this->state = 'idle';
            throw $error;
        }
        $leases[$this->pdo] = true;
        $this->state = 'active';
    }

    private function commit(): void
    {
        if ($this->state !== 'active') {
            throw new RuntimeException('تراکنش فعال برای ثبت وجود ندارد.');
        }
        $this->state = 'committing';
        if ($this->driver === 'sqlite') {
            if ($this->pdo->exec('COMMIT') === false) {
                throw new RuntimeException('ثبت تراکنش SQLite ناموفق بود.');
            }
        } elseif (!$this->pdo->commit()) {
            throw new RuntimeException('ثبت تراکنش PostgreSQL ناموفق بود.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('پایگاه داده پس از COMMIT همچنان داخل تراکنش است.');
        }
        $this->state = 'committed';
        $this->releaseLease();
    }

    private function rollbackOrPoison(Throwable $cause): void
    {
        if (!in_array($this->state, ['active', 'committing'], true)) {
            return;
        }
        try {
            if ($this->driver === 'sqlite') {
                if ($this->pdo->exec('ROLLBACK') === false) {
                    throw new RuntimeException('بازگردانی تراکنش SQLite ناموفق بود.');
                }
            } elseif (!$this->pdo->rollBack()) {
                throw new RuntimeException('بازگردانی تراکنش PostgreSQL ناموفق بود.');
            }
            if ($this->pdo->inTransaction()) {
                throw new RuntimeException('پایگاه داده پس از ROLLBACK همچنان داخل تراکنش است.');
            }
            $this->state = 'rolled_back';
            $this->releaseLease();
        } catch (Throwable $rollbackError) {
            self::poisoned()[$this->pdo] = true;
            $this->state = 'ambiguous';
            $this->releaseLease();
            throw new RuntimeException(
                'وضعیت تراکنش پایگاه داده مبهم است و اتصال باید کنار گذاشته شود.',
                0,
                $rollbackError,
            );
        }
    }

    private function releaseLease(): void
    {
        $leases = self::leases();
        if (isset($leases[$this->pdo])) {
            unset($leases[$this->pdo]);
        }
    }

    private static function driver(PDO $pdo): string
    {
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $error) {
            throw new RuntimeException('درایور پایگاه داده قابل اثبات نیست.', 0, $error);
        }
        if (!in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('درایور پایگاه داده برای تراکنش هویت پشتیبانی نمی‌شود.');
        }
        return $driver;
    }

    private static function leases(): WeakMap
    {
        return self::$leases ??= new WeakMap();
    }

    private static function poisoned(): WeakMap
    {
        return self::$poisoned ??= new WeakMap();
    }
}
