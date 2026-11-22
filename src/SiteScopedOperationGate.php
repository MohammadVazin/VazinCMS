<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

/**
 * Explicit guard for legacy write paths not yet migrated to site-scoped
 * tables and per-site permissions. Never reinterpret a global owner role
 * as authorization to mutate another site's data.
 *
 * This is NOT the main SiteIsolationGate (which blocks all multi-site HTTP).
 * It is defense in depth for direct workers/CLI/module calls on scoped DBs.
 */
final class SiteScopedOperationGate
{
    private const UNMIGRATED=[
        'extension_registry',
        'legacy_scheduler',
        'seo_analysis',
        'editorial_planner',
        'editorial_transition',
        'scheduled_publish',
        'external_publication',
        'media_archive',
        'telegram_sync',
        'telegram_outbound',
    ];

    public static function denyUnscopedWrite(PDO $pdo,string $operation): void
    {
        if (!in_array($operation,self::UNMIGRATED,true)) {
            throw new RuntimeException('tenant_unrecognized_operation');
        }
        try {
            $scoped=SiteScopeMode::active($pdo);
        } catch(RuntimeException $error) {
            if ($error->getMessage()!=='tenant_schema_missing_table') {
                throw $error;
            }
            // Pre-multisite CMS bootstrap tests can have no cms_sites and
            // only a subset of legacy tables. They have no tenant data.
            // Do NOT extend this exception to partially migrated schemas.
            $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver==='sqlite') {
                $siteTable=(int)$pdo->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='cms_sites'"
                )->fetchColumn();
                if ($siteTable!==0)throw $error;
                foreach($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    if(!preg_match('/^[A-Za-z0-9_]+$/',$table))throw $error;
                    foreach($pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                        if(($col['name']??'')==='site_id') {
                            throw new RuntimeException('tenant_schema_partially_migrated');
                        }
                    }
                }
                return;
            }
            if ($driver==='pgsql') {
                $exists=$pdo->query(
                    "SELECT to_regclass(current_schema() || '.cms_sites')"
                )->fetchColumn();
                $siteColumns=(int)$pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns " .
                    "WHERE table_schema=current_schema() AND column_name='site_id'"
                )->fetchColumn();
                if ($exists===null && $siteColumns===0)return;
            }
            throw $error;
        }
        if ($scoped) {
            throw new RuntimeException('tenant_'. $operation .'_requires_site_isolation');
        }
    }
}
