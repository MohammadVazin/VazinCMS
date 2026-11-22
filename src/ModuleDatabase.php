<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Isolated, SQL-injection-safe, multi-tenant database storage for Schema v2 modules.
 *
 * Security guarantees:
 * 1. Every table is strictly prefixed with `mod_<normalized_module_key>_`.
 *    Modules can NEVER read or write core tables (`users`, `cms_settings`, `api_tokens`, etc.).
 * 2. Every row is automatically scoped to the active `site_id` so multi-tenant
 *    isolation holds by construction across SQLite, PostgreSQL, and MySQL.
 * 3. Only validated identifiers and parameterized bindings are executed.
 */
final class ModuleDatabase
{
    private string $prefix;

    public function __construct(
        private PDO $pdo,
        private string $moduleKey,
        private ?int $forcedSiteId = null
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,60}$/', $moduleKey) !== 1) {
            throw new InvalidArgumentException('Invalid module key for database isolation.');
        }
        $this->prefix = 'mod_' . str_replace('-', '_', $moduleKey) . '_';
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function tableName(string $shortName): string
    {
        $shortName = strtolower(trim($shortName));
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $shortName) !== 1) {
            throw new InvalidArgumentException('نام جدول ماژول معتبر نیست: ' . $shortName);
        }
        return $this->prefix . $shortName;
    }

    public function currentSiteId(): int
    {
        if ($this->forcedSiteId !== null && $this->forcedSiteId > 0) {
            return $this->forcedSiteId;
        }
        try {
            $id = SiteContext::id();
            return $id > 0 ? $id : 1;
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Portable schema builder across SQLite, PostgreSQL, and MySQL.
     * Automatically provisions `id`, `site_id`, `created_at`, `updated_at`.
     *
     * @param array<string,string> $columns e.g. ['sku' => 'string:80', 'title' => 'string:255', 'price' => 'decimal', 'stock' => 'int', 'meta_json' => 'json:nullable']
     * @param list<list<string>|string> $uniqueIndexes
     */
    public function ensureTable(string $shortName, array $columns, array $uniqueIndexes = []): void
    {
        $table = $this->tableName($shortName);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $defs = [];
        if ($driver === 'sqlite') {
            $defs[] = 'id INTEGER PRIMARY KEY AUTOINCREMENT';
            $defs[] = 'site_id INTEGER NOT NULL DEFAULT 1';
        } elseif ($driver === 'mysql') {
            $defs[] = 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $defs[] = 'site_id BIGINT NOT NULL DEFAULT 1';
        } else {
            $defs[] = 'id BIGSERIAL PRIMARY KEY';
            $defs[] = 'site_id BIGINT NOT NULL DEFAULT 1';
        }

        $reserved = ['id' => true, 'site_id' => true, 'created_at' => true, 'updated_at' => true];
        foreach ($columns as $col => $spec) {
            self::assertIdentifier($col);
            if (isset($reserved[$col])) {
                throw new InvalidArgumentException('ستون رزروشده سیستمی قابل بازتعریف نیست: ' . $col);
            }
            $defs[] = $col . ' ' . self::compileColumnSpec($driver, $spec);
        }

        $defs[] = 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $defs[] = 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';

        $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode(', ', $defs) . ')';
        if ($driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        $this->pdo->exec($sql);

        $siteIdx = 'idx_' . substr(hash('sha256', $table . '_site'), 0, 16);
        $this->createIndexIfMissing($driver, $table, $siteIdx, ['site_id'], false);

        foreach ($uniqueIndexes as $i => $idxCols) {
            $cols = is_array($idxCols) ? $idxCols : [$idxCols];
            foreach ($cols as $c) {
                self::assertIdentifier($c);
            }
            $scopedCols = array_values(array_unique(array_merge(['site_id'], $cols)));
            $idxName = 'uidx_' . substr(hash('sha256', $table . '_' . $i . '_' . implode('_', $scopedCols)), 0, 16);
            $this->createIndexIfMissing($driver, $table, $idxName, $scopedCols, true);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insert(string $shortName, array $data): int
    {
        $table = $this->tableName($shortName);
        unset($data['id'], $data['site_id'], $data['created_at'], $data['updated_at']);
        if ($data === []) {
            throw new InvalidArgumentException('داده درج در جدول ماژول نمی‌تواند خالی باشد.');
        }

        $cols = ['site_id'];
        $placeholders = [':_site_id'];
        $bindings = ['_site_id' => $this->currentSiteId()];

        foreach ($data as $col => $val) {
            self::assertIdentifier($col);
            $cols[] = $col;
            $placeholders[] = ':c_' . $col;
            $bindings['c_' . $col] = self::normalizeValue($val);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(string $shortName, int $id, array $data): bool
    {
        $table = $this->tableName($shortName);
        unset($data['id'], $data['site_id'], $data['created_at'], $data['updated_at']);
        if ($data === [] || $id < 1) {
            return false;
        }

        $sets = [];
        $bindings = ['_id' => $id, '_site_id' => $this->currentSiteId()];
        foreach ($data as $col => $val) {
            self::assertIdentifier($col);
            $sets[] = $col . ' = :u_' . $col;
            $bindings['u_' . $col] = self::normalizeValue($val);
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = :_id AND site_id = :_site_id',
            $table,
            implode(', ', $sets)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount() > 0;
    }

    public function delete(string $shortName, int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $table = $this->tableName($shortName);
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id AND site_id = :site_id");
        $stmt->execute(['id' => $id, 'site_id' => $this->currentSiteId()]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return ?array<string,mixed>
     */
    public function find(string $shortName, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $table = $this->tableName($shortName);
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id AND site_id = :site_id LIMIT 1");
        $stmt->execute(['id' => $id, 'site_id' => $this->currentSiteId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $criteria
     * @return ?array<string,mixed>
     */
    public function findOneBy(string $shortName, array $criteria): ?array
    {
        $rows = $this->where($shortName, $criteria, 'id DESC', 1, 0);
        return $rows[0] ?? null;
    }

    /**
     * @param array<string,mixed> $criteria
     * @return list<array<string,mixed>>
     */
    public function where(
        string $shortName,
        array $criteria = [],
        string $orderBy = 'id DESC',
        int $limit = 100,
        int $offset = 0
    ): array {
        $table = $this->tableName($shortName);
        [$whereSql, $bindings] = $this->compileCriteria($criteria);
        $orderSql = self::compileOrderBy($orderBy);
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $sql = "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY {$orderSql} LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function count(string $shortName, array $criteria = []): int
    {
        $table = $this->tableName($shortName);
        [$whereSql, $bindings] = $this->compileCriteria($criteria);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}");
        $stmt->execute($bindings);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array{0:string,1:array<string,mixed>}
     */
    private function compileCriteria(array $criteria): array
    {
        $clauses = ['site_id = :_site_id'];
        $bindings = ['_site_id' => $this->currentSiteId()];
        $idx = 0;
        foreach ($criteria as $col => $val) {
            self::assertIdentifier((string) $col);
            $idx++;
            if ($val === null) {
                $clauses[] = "{$col} IS NULL";
            } elseif (is_array($val)) {
                if ($val === []) {
                    $clauses[] = '1 = 0';
                    continue;
                }
                $inKeys = [];
                foreach (array_values($val) as $j => $item) {
                    $p = "w_{$idx}_{$j}";
                    $inKeys[] = ':' . $p;
                    $bindings[$p] = self::normalizeValue($item);
                }
                $clauses[] = $col . ' IN (' . implode(', ', $inKeys) . ')';
            } else {
                $p = "w_{$idx}";
                $clauses[] = "{$col} = :{$p}";
                $bindings[$p] = self::normalizeValue($val);
            }
        }
        return [implode(' AND ', $clauses), $bindings];
    }

    private static function compileOrderBy(string $orderBy): string
    {
        $parts = array_filter(array_map('trim', explode(',', $orderBy)));
        if ($parts === []) {
            return 'id DESC';
        }
        $compiled = [];
        foreach ($parts as $part) {
            if (preg_match('/^([a-z][a-z0-9_]{0,60})(?:\s+(ASC|DESC))?$/i', $part, $m) !== 1) {
                throw new InvalidArgumentException('عبارت مرتب‌سازی معتبر نیست.');
            }
            $dir = strtoupper($m[2] ?? 'ASC');
            $compiled[] = strtolower($m[1]) . ' ' . $dir;
        }
        return implode(', ', $compiled);
    }

    private static function compileColumnSpec(string $driver, string $spec): string
    {
        $tokens = explode(':', strtolower(trim($spec)));
        $base = $tokens[0] ?? 'string';
        $nullable = in_array('nullable', $tokens, true);
        $length = 255;
        foreach ($tokens as $tok) {
            if (ctype_digit($tok)) {
                $length = max(1, min(4096, (int) $tok));
            }
        }

        $sqlType = match ($base) {
            'string', 'varchar' => "VARCHAR({$length})",
            'text', 'json'      => 'TEXT',
            'int', 'integer'    => 'INTEGER',
            'bigint'            => 'BIGINT',
            'decimal', 'price'  => $driver === 'sqlite' ? ' NUMERIC' : 'DECIMAL(14,2)',
            'bool', 'boolean'   => $driver === 'pgsql' ? 'BOOLEAN' : 'INTEGER',
            'datetime', 'timestamp' => 'TIMESTAMP',
            default => throw new InvalidArgumentException('نوع ستون ماژول پشتیبانی نمی‌شود: ' . $base),
        };

        $nullClause = $nullable ? 'NULL' : 'NOT NULL';
        $defaultClause = '';
        foreach ($tokens as $tok) {
            if (str_starts_with($tok, 'default=')) {
                $rawDef = substr($tok, 8);
                if (preg_match('/^-?\d+(?:\.\d+)?$/', $rawDef) === 1) {
                    $defaultClause = ' DEFAULT ' . $rawDef;
                } elseif (preg_match('/^[a-z0-9_-]{0,40}$/', $rawDef) === 1) {
                    $defaultClause = " DEFAULT '" . $rawDef . "'";
                }
            }
        }
        if ($defaultClause === '' && !$nullable) {
            $defaultClause = match ($base) {
                'int', 'integer', 'bigint', 'decimal', 'price' => ' DEFAULT 0',
                'bool', 'boolean' => $driver === 'pgsql' ? ' DEFAULT FALSE' : ' DEFAULT 0',
                'string', 'varchar', 'text', 'json' => " DEFAULT ''",
                default => '',
            };
        }

        return trim($sqlType) . ' ' . $nullClause . $defaultClause;
    }

    /**
     * @param list<string> $cols
     */
    private function createIndexIfMissing(string $driver, string $table, string $idxName, array $cols, bool $unique): void
    {
        $u = $unique ? 'UNIQUE ' : '';
        $colList = implode(', ', $cols);
        if ($driver === 'mysql') {
            $check = $this->pdo->prepare('SHOW INDEX FROM ' . $table . ' WHERE Key_name = :k');
            $check->execute(['k' => $idxName]);
            if (!$check->fetch()) {
                $this->pdo->exec("CREATE {$u}INDEX {$idxName} ON {$table} ({$colList})");
            }
            return;
        }
        $this->pdo->exec("CREATE {$u}INDEX IF NOT EXISTS {$idxName} ON {$table} ({$colList})");
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,60}$/', $name) !== 1) {
            throw new InvalidArgumentException('شناسه ستون مجاز نیست: ' . $name);
        }
    }

    private static function normalizeValue(mixed $val): mixed
    {
        if (is_bool($val)) {
            return $val ? 1 : 0;
        }
        if (is_array($val) || is_object($val)) {
            return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return $val;
    }
}
