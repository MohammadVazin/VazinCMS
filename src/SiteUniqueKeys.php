<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

/**
 * Exact conflict-target discovery for the transitional schema.
 *
 * Before the reviewed composite-key migration, single-site candidate QA uses
 * the global uniqueness shape; afterward it uses (site_id,key).
 * Never silently use a guessed conflict target. Global SiteIsolationGate
 * remains active and blocks multi-site HTTP regardless of the selected mode.
 */
final class SiteUniqueKeys
{
    public static function target(PDO $pdo,string $table,string $key): string
    {
        $allowed=[
            'cms_settings'=>'setting_key',
            'cms_redirects'=>'source_path',
            'cms_reusable_blocks'=>'block_key',
            'cms_block_patterns'=>'pattern_key',
            'cms_site_import_jobs'=>'source_fingerprint',
            'cms_site_editorial_suggestions'=>'suggestion_key',
        ];
        if(($allowed[$table]??null)!==$key){
            throw new RuntimeException('tenant_unique_target_unsupported');
        }
        $indexes=self::uniqueIndexes($pdo,$table);
        $legacy=[$key];
        $scoped=['site_id',$key];
        $hasLegacy=in_array($legacy,$indexes,true);
        $hasScoped=in_array($scoped,$indexes,true);
        if($hasLegacy&&$hasScoped){
            throw new RuntimeException('tenant_mixed_unique_constraints');
        }
        if($hasScoped)return '(site_id,'.$key.')';
        if($hasLegacy)return '('.$key.')';
        throw new RuntimeException('tenant_unique_constraint_missing');
    }

    private static function uniqueIndexes(PDO $pdo,string $table):array
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){
            $out=[];
            foreach($pdo->query('PRAGMA index_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $index){
                if(empty($index['unique']))continue;
                $columns=[];
                foreach($pdo->query('PRAGMA index_info("'.$index['name'].'")')->fetchAll(PDO::FETCH_ASSOC) as $column){
                    $columns[]=(string)$column['name'];
                }
                $out[]=$columns;
            }
            return $out;
        }
        if($driver==='pgsql'){
            $q=$pdo->prepare(
                'SELECT string_agg(a.attname, \',\' ORDER BY keys.ord) AS columns ' .
                'FROM pg_constraint c ' .
                'JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS keys(attnum,ord) ON TRUE ' .
                'JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=keys.attnum ' .
                'WHERE c.conrelid=to_regclass(:table) AND c.contype IN (\'p\',\'u\') ' .
                'GROUP BY c.oid'
            );
            $q->execute(['table'=>$table]);
            return array_map(static fn(string $s):array=>explode(',',$s),
                $q->fetchAll(PDO::FETCH_COLUMN));
        }
        throw new RuntimeException('tenant_driver_unsupported');
    }
}
