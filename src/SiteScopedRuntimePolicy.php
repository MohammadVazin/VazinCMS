<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

final class SiteScopedRuntimePolicy
{
    private const SAFE_BUNDLED_MODULES = [
        'seo' => true,
        'content-migration' => true,
        'vazin-commerce' => true,
        'vazin-lms' => true,
        'vazin-booking' => true,
        'vazin-forms' => true,
        'vazin-comments' => true,
        'vazin-analytics' => true,
        'vazin-memberships' => true,
    ];
    private const SAFE_BUNDLED_THEMES = [
        'vazin-default' => true,
        'vazin-editorial' => true,
        'vazin-storefront' => true,
    ];

    public static function scopedMode(PDO $pdo): bool
    {
        try {
            return SiteScopeMode::active($pdo);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'tenant_schema_missing_table') {
                throw $error;
            }
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $exists = (int) $pdo->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='cms_sites'"
                )->fetchColumn();
                if ($exists !== 0) throw $error;
                foreach ($pdo->query(
                    "SELECT name FROM sqlite_master WHERE type='table'"
                )->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    if (!preg_match('/^[A-Za-z0-9_]+$/D', (string) $table)) {
                        throw $error;
                    }
                    foreach ($pdo->query(
                        'PRAGMA table_info("' . $table . '")'
                    )->fetchAll(PDO::FETCH_ASSOC) as $column) {
                        if (($column['name'] ?? '') === 'site_id') {
                            throw new RuntimeException('tenant_schema_partially_migrated');
                        }
                    }
                }
                return false;
            }
            if ($driver === 'pgsql') {
                $sites = $pdo->query(
                    "SELECT to_regclass(current_schema() || '.cms_sites')"
                )->fetchColumn();
                $columns = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns " .
                    "WHERE table_schema=current_schema() AND column_name='site_id'"
                )->fetchColumn();
                if ($sites === null && $columns === 0) return false;
            }
            throw $error;
        }
    }

    public static function moduleAllowed(array $row): bool
    {
        $key = (string) ($row['extension_key'] ?? '');
        return isset(self::SAFE_BUNDLED_MODULES[$key]) &&
            ($row['extension_type'] ?? null) === 'module' &&
            ($row['source'] ?? null) === 'bundled' &&
            ($row['status'] ?? null) === 'active';
    }

    public static function themeAllowed(array $row): bool
    {
        $key = (string) ($row['extension_key'] ?? '');
        return isset(self::SAFE_BUNDLED_THEMES[$key]) &&
            ($row['extension_type'] ?? null) === 'theme' &&
            ($row['source'] ?? null) === 'bundled' &&
            ($row['status'] ?? null) === 'active';
    }

    public static function extensionAllowed(array $row): bool
    {
        return self::moduleAllowed($row) || self::themeAllowed($row);
    }
}
