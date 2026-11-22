<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * SQLite 10.31.1 tenant-scoped uniqueness table rebuild.
 *
 * STAGING/REVIEW ONLY. Not installed on Production. This transaction-safe
 * implementation ports the independently tested offline phase4 SQL rebuild.
 * It is deliberately NOT generic SQL rewriting for arbitrary user tables.
 *
 * migrate.php must call apply() after 10.31.0, outside any transaction. It
 * records 10.31.1 inside the SAME SQLite BEGIN IMMEDIATE transaction as all
 * table/index/trigger changes, then restores PRAGMA foreign_keys=ON.
 */
final class SiteTenantSqliteUniqueMigrator
{
    private const TARGETS=[
        'cms_pages'=>['UNIQUE(slug,locale)','UNIQUE(site_id,slug,locale)'],
        'cms_settings'=>['setting_key TEXT PRIMARY KEY','setting_key TEXT NOT NULL'],
        'cms_redirects'=>['source_path TEXT NOT NULL UNIQUE','source_path TEXT NOT NULL'],
        'cms_media'=>['stored_name TEXT NOT NULL UNIQUE','stored_name TEXT NOT NULL'],
        'cms_taxonomies'=>['taxonomy_key TEXT NOT NULL UNIQUE','taxonomy_key TEXT NOT NULL'],
        'cms_content_types'=>['content_key TEXT NOT NULL UNIQUE','content_key TEXT NOT NULL'],
        'cms_forms'=>['form_key TEXT NOT NULL UNIQUE','form_key TEXT NOT NULL'],
        'cms_reusable_blocks'=>['block_key TEXT NOT NULL UNIQUE','block_key TEXT NOT NULL'],
        'cms_block_patterns'=>['pattern_key TEXT NOT NULL UNIQUE','pattern_key TEXT NOT NULL'],
        'cms_media_collections'=>['collection_key TEXT NOT NULL UNIQUE','collection_key TEXT NOT NULL'],
        'cms_custom_field_definitions'=>['field_key TEXT NOT NULL UNIQUE','field_key TEXT NOT NULL'],
    ];
    private const UNIQUE_KEYS=[
        'cms_pages'=>['site_id','slug','locale'],
        'cms_settings'=>['site_id','setting_key'],
        'cms_redirects'=>['site_id','source_path'],
        'cms_media'=>['site_id','stored_name'],
        'cms_taxonomies'=>['site_id','taxonomy_key'],
        'cms_content_types'=>['site_id','content_key'],
        'cms_forms'=>['site_id','form_key'],
        'cms_reusable_blocks'=>['site_id','block_key'],
        'cms_block_patterns'=>['site_id','pattern_key'],
        'cms_media_collections'=>['site_id','collection_key'],
        'cms_custom_field_definitions'=>['site_id','field_key'],
    ];
    private const NULLABLE='site_id INTEGER REFERENCES cms_sites(id)';
    private const REQUIRED='site_id INTEGER NOT NULL REFERENCES cms_sites(id)';

    private static function need(bool $ok,string $why):void
    {
        if(!$ok)throw new RuntimeException('tenant_sqlite_unique_precondition:'.$why);
    }

    /** Read a scalar and explicitly finalize the native PDO_SQLITE cursor
     * BEFORE any subsequent DROP/ALTER TABLE in the same transaction. */
    private static function scalar(PDO $db,string $query):mixed
    {
        $cursor=$db->query($query);
        try{return $cursor->fetchColumn();}
        finally{$cursor->closeCursor();}
    }

    /** @return list<list<string>> */
    private static function indexes(PDO $db,string $table):array
    {
        $out=[];
        $stmt=$db->query('PRAGMA index_list("'.$table.'")');
        try{$rows=$stmt->fetchAll(PDO::FETCH_NUM);}
        finally{$stmt->closeCursor();}
        foreach($rows as $index){
            if((int)$index[2]!==1)continue;
            $name=str_replace('"','""',(string)$index[1]);
            $keys=[];
            $keyStmt=$db->query('PRAGMA index_info("'.$name.'")');
            try{$columns=$keyStmt->fetchAll(PDO::FETCH_NUM);}
            finally{$keyStmt->closeCursor();}
            foreach($columns as $col){
                $keys[]=(string)$col[2];
            }
            $out[]=$keys;
        }
        return $out;
    }

    /** @return list<string> */
    private static function sqlObjects(PDO $db,string $type,string $table):array
    {
        $q=$db->prepare(
            'SELECT sql FROM sqlite_master WHERE type=:type AND tbl_name=:tbl ' .
            'AND sql IS NOT NULL ORDER BY name'
        );
        $q->execute(['type'=>$type,'tbl'=>$table]);
        try{return array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));}
        finally{$q->closeCursor();}
    }

    /**
     * @param ?string $injectFailureAfter ONLY for disposable QA with explicit
     *                     opt-in; never supplied by the official migrator.
     */
    public static function apply(
        PDO $db,string $version='10.31.1',?string $injectFailureAfter=null
    ):void {
        self::need($version==='10.31.1','unexpected_version');
        self::need($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite','sqlite_only');
        self::need(!$db->inTransaction(),'unexpected_ambient_transaction');
        if($injectFailureAfter!==null){
            self::need(
                getenv('VAZINCMS_MIGRATION_QA_SCOPE')==='disposable' &&
                isset(self::TARGETS[$injectFailureAfter]),
                'invalid_fault_injection_scope'
            );
        }
        self::need((int)self::scalar($db,'PRAGMA foreign_keys')===1,
                   'foreign_keys_required_on');
        self::need((int)self::scalar($db,'SELECT COUNT(*) FROM cms_sites')===1,
                   'exactly_one_site');
        self::need((int)self::scalar($db,
            "SELECT COUNT(*) FROM cms_sites WHERE site_key='default' AND status='active'"
        )===1,'active_default_required');
        self::need((int)self::scalar($db,
            "SELECT COUNT(*) FROM schema_migrations WHERE version='10.31.1'"
        )===0,'already_applied');

        $prepared=[];
        foreach(self::TARGETS as $table=>[$old,$replacement]){
            $q=$db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=:name");
            $q->execute(['name'=>$table]);
            $ddl=$q->fetchColumn();
            $q->closeCursor();
            self::need(is_string($ddl)&&$ddl!=='',$table.'_missing');
            self::need(
                substr_count($ddl,self::NULLABLE)===1 &&
                !str_contains($ddl,self::REQUIRED),
                $table.'_site_column_not_nullable_expected'
            );
            self::need(substr_count($ddl,$old)===1,
                       $table.'_unexpected_original_unique');
            $expected=self::UNIQUE_KEYS[$table];
            $prior=array_slice($expected,1);
            $unique=self::indexes($db,$table);
            self::need(in_array($prior,$unique,true),
                       $table.'_global_unique_missing');
            $strict=str_replace(self::NULLABLE,self::REQUIRED,$ddl,$count1);
            self::need($count1===1,$table.'_site_column_rewrite_failed');
            $strict=str_replace($old,$replacement,$strict,$count2);
            self::need($count2===1,$table.'_unique_rewrite_failed');
            if($table!=='cms_pages'){
                $strict=rtrim($strict);
                self::need(str_ends_with($strict,')'),$table.'_invalid_ddl_suffix');
                $strict=substr($strict,0,-1).
                    ', UNIQUE('.implode(',',$expected).'))';
            }
            $count3=0;
            $shadow='__vazin_uq_'.$table;
            $pattern='/^(CREATE\s+TABLE\s+)(?:"?'.
                preg_quote($table,'/').'"?)\s*\(/i';
            $replacementFn=static fn(array $m):string =>
                $m[1].'"'.$shadow.'" (';
            $strict=preg_replace_callback($pattern,$replacementFn,$strict,1,$count3);
            self::need(is_string($strict)&&$count3===1 &&
                str_contains($strict,self::REQUIRED),
                $table.'_shadow_name_failed');

            $objects=[
                'create'=>$strict,
                'indexes'=>self::sqlObjects($db,'index',$table),
                'triggers'=>self::sqlObjects($db,'trigger',$table),
            ];
            if($table==='cms_media'){
                $found=0;
                foreach($objects['indexes'] as &$sql){
                    if(preg_match('/CREATE\s+UNIQUE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?idx_cms_media_sha256\s+ON\s+cms_media\s*\(\s*sha256\s*\)/i',
                                  $sql)){
                        $sql=preg_replace('/\(\s*sha256\s*\)/i',
                            '(site_id,sha256)',$sql,1,$replacements);
                        self::need(is_string($sql)&&$replacements===1,
                            'media_sha_index_transform_failed');
                        $found++;
                    }elseif(str_contains($sql,'idx_cms_media_sha256')){
                        throw new RuntimeException(
                            'tenant_sqlite_unique_precondition:media_sha_index_drift'
                        );
                    }
                }
                unset($sql);
                self::need($found===1,'media_sha_index_missing');
            }
            $prepared[$table]=$objects;
        }
        $triggerCursor=$db->query(
            "SELECT name,sql FROM sqlite_master WHERE type='trigger' " .
            'AND sql IS NOT NULL ORDER BY name'
        );
        try{$allTriggers=$triggerCursor->fetchAll(PDO::FETCH_ASSOC);}
        finally{$triggerCursor->closeCursor();}

        // FK checking is turned off ONLY for this connection during the
        // atomic table swap. PRAGMA foreign_key_check is run BEFORE COMMIT.
        $db->exec('PRAGMA foreign_keys=OFF');
        self::need((int)self::scalar($db,'PRAGMA foreign_keys')===0,
            'failed_to_disable_fk_in_scope');
        $inSqlTransaction=false;
        try{
            $db->exec('BEGIN IMMEDIATE');
            $inSqlTransaction=true;
            foreach($allTriggers as $t){
                $name=str_replace('"','""',(string)$t['name']);
                $db->exec('DROP TRIGGER "'.$name.'"');
            }
            foreach($prepared as $table=>$objects){
                $columnCursor=$db->query('PRAGMA table_info("'.$table.'")');
                try{$columns=array_map('strval',
                    $columnCursor->fetchAll(PDO::FETCH_COLUMN,1));}
                finally{$columnCursor->closeCursor();}
                self::need(in_array('site_id',$columns,true),
                    $table.'_copy_columns_missing');
                self::need((int)self::scalar($db,
                    'SELECT COUNT(*) FROM "'.$table.'" WHERE site_id IS NULL'
                )===0,$table.'_unowned_legacy_rows');
                $fields=implode(',',array_map(
                    static fn(string $col):string=>'"'.str_replace('"','""',$col).'"',
                    $columns
                ));
                $shadow='__vazin_uq_'.$table;
                $db->exec($objects['create']);
                $db->exec('INSERT INTO "'.$shadow.'" ('.$fields.') ' .
                    'SELECT '.$fields.' FROM "'.$table.'"');
                $sourceRows=(int)self::scalar($db,
                    'SELECT COUNT(*) FROM "'.$table.'"');
                $copiedRows=(int)self::scalar($db,
                    'SELECT COUNT(*) FROM "'.$shadow.'"');
                self::need($sourceRows===$copiedRows,
                    $table.'_row_count_changed');
                $db->exec('DROP TABLE "'.$table.'"');
                $db->exec('ALTER TABLE "'.$shadow.'" RENAME TO "'.$table.'"');
                foreach($objects['indexes'] as $sql)$db->exec($sql);
                if($injectFailureAfter===$table){
                    throw new RuntimeException(
                        'tenant_sqlite_unique_injected_failure'
                    );
                }
                $indexes=self::indexes($db,$table);
                self::need(in_array(self::UNIQUE_KEYS[$table],$indexes,true),
                    $table.'_new_composite_unique_missing');
                self::need(!in_array(array_slice(self::UNIQUE_KEYS[$table],1),
                    $indexes,true),$table.'_old_global_unique_not_removed');
            }
            foreach($allTriggers as $trigger)$db->exec((string)$trigger['sql']);
            foreach($prepared as $table=>$objects){
                $q=$db->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND tbl_name=:tbl"
                );
                $q->execute(['tbl'=>$table]);
                $actual=(int)$q->fetchColumn();
                $q->closeCursor();
                self::need($actual===count($objects['triggers'])&&$actual>=2,
                    $table.'_trigger_loss');
            }
            self::need((int)self::scalar($db,
                "SELECT COUNT(*) FROM sqlite_master WHERE type='trigger'"
            )===count($allTriggers),'global_trigger_loss');
            $fkCursor=$db->query('PRAGMA foreign_key_check');
            try{$fkViolations=$fkCursor->fetchAll(PDO::FETCH_NUM);}
            finally{$fkCursor->closeCursor();}
            self::need(count($fkViolations)===0,'fk_violation');
            self::need(self::scalar($db,'PRAGMA integrity_check')==='ok',
                'database_integrity_failed');
            $mark=$db->prepare(
                'INSERT OR IGNORE INTO schema_migrations(version) VALUES(:version)'
            );
            $mark->execute(['version'=>$version]);
            self::need($mark->rowCount()===1,'registry_mark_failed');
            $db->exec('COMMIT');
            $inSqlTransaction=false;
        }catch(Throwable $error){
            if($inSqlTransaction){
                try{$db->exec('ROLLBACK');}catch(Throwable){}
            }
            throw $error;
        }finally{
            $db->exec('PRAGMA foreign_keys=ON');
        }
    }
}
