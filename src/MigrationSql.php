<?php
declare(strict_types=1);

namespace VazinCMS;

/**
 * PHP PDO_pgsql does not accept comment-only SQL in PDO::exec().
 * Every versioned migration must still be recorded transactionally.
 */
final class MigrationSql
{
    public static function containsStatements(string $sql): bool
    {
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--')) {
                return true;
            }
        }
        return false;
    }
}
