<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

/**
 * A whole-schema, fail-closed feature probe.
 *
 * An unmigrated legacy installation stays single-site only (enforced globally
 * by SiteIsolationGate). An incomplete tenant migration MUST NEVER be treated
 * as legacy. This prevents an unscoped route from silently returning all sites
 * when only a subset of tables has been migrated.
 */
final class SiteScopeMode
{
    private const TABLES = [
        'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
        'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
        'cms_autosaves','cms_search_index','cms_media_collections',
        'cms_media_derivatives','cms_media_collection_items','cms_content_types',
        'cms_forms','cms_form_fields','cms_form_submissions','cms_reusable_blocks',
        'cms_block_patterns','cms_editorial_events','publication_jobs',
        'cms_custom_field_definitions','cms_custom_field_values',
    ];

    public static function current(PDO $pdo): string
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $found = 0;
            foreach (self::TABLES as $table) {
                $columns = $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
                if (!$columns) throw new RuntimeException('tenant_schema_missing_table');
                foreach ($columns as $column) {
                    if (($column['name'] ?? '') === 'site_id') {
                        $found++;
                        break;
                    }
                }
            }
        } elseif ($driver === 'pgsql') {
            $params=[];
            foreach(self::TABLES as $i=>$name) $params['p'.$i]=$name;
            $sql="SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() " .
                "AND column_name='site_id' AND table_name IN (".
                implode(',',array_map(static fn(string $k)=>':'.$k,array_keys($params))).')';
            $q=$pdo->prepare($sql);
            $q->execute($params);
            $found=(int)$q->fetchColumn();
        } else {
            throw new RuntimeException('tenant_driver_unsupported');
        }
        if ($found===count(self::TABLES)) return 'scoped';
        if ($found!==0) throw new RuntimeException('tenant_schema_partially_migrated');
        // Never allow a multi-site schema to use global legacy SQL.
        $sites=(int)$pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn();
        if ($sites!==1) throw new RuntimeException('tenant_legacy_multisite_denied');
        return 'legacy';
    }
    public static function active(PDO $pdo): bool
    {
        return self::current($pdo)==='scoped';
    }
}
