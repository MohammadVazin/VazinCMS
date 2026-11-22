<?php
declare(strict_types=1);

/** Candidate-only schema provenance contract: does not execute migrations. */
$root=dirname(__DIR__);
$expect=static function(bool $ok,string $what):void{
    if(!$ok)throw new RuntimeException('TENANT_STAGED_MIGRATION_CONTRACT_FAIL:'.$what);
};
$all=[];
foreach(['pgsql','sqlite'] as $driver){
    for($i=0;$i<=8;$i++){
        $version="10.31.$i";
        $path="$root/database/migrations/$version-$driver.sql";
        $expect(is_file($path),"$version.$driver.file_missing");
        $sql=file_get_contents($path);
        $expect(is_string($sql)&&$sql!=='',"$version.$driver.empty");
        $all[$driver][$i]=$sql;
    }
}
$expect(count($all['sqlite'])===9 && count($all['pgsql'])===9,'file_count');
foreach($all['pgsql'] as $i=>$sql){
    $expect(!preg_match('/^\s*(?:BEGIN|COMMIT|ROLLBACK)\s*;/mi',$sql),
            "pg_nested_transaction_$i");
    $expect(str_contains($sql,"10.31.$i") && 
            str_contains($sql,'Source SHA256:'),
            "pg_source_provenance_$i");
}
$expect(str_contains($all['sqlite'][1],'SQLITE_REBUILD_TABLES_10_31_1'),
        'special_rebuild_marker_missing');
$expect(str_contains($all['sqlite'][7],'SQLITE_AUTOINCREMENT_ALREADY_TRACKS'),
        'sqlite_serial_nop_marker_missing');
$helper=file_get_contents($root.'/src/SiteTenantSqliteUniqueMigrator.php');
$expect(is_string($helper) &&
        str_contains($helper,'class SiteTenantSqliteUniqueMigrator') &&
        str_contains($helper,"PRAGMA foreign_keys=ON") &&
        str_contains($helper,"INSERT OR IGNORE INTO schema_migrations"),
        'transactional_sqlite_helper_absent');
$runner=file_get_contents($root.'/scripts/migrate.php');
$expect(is_string($runner) &&
        str_contains($runner,"$".'version === '."'10.31.1'") &&
        str_contains($runner,'SiteTenantSqliteUniqueMigrator::apply') &&
        str_contains($runner,'$hasExecutableStatement'),
        'official_php_migrator_adapter_missing');
$gate=file_get_contents($root.'/src/SiteScopedReleaseGate.php');
$expect(is_string($gate) &&
        str_contains($gate,'staged_pg_and_sqlite_migrations_complete') &&
        str_contains($gate,'$migrationReady=false;'),
        'signed_release_not_fail_closed');
echo "TENANT_STAGED_MIGRATIONS=PASS pg=9 sqlite=9,sqlite_rebuild_adapter,signing_still_blocked\n";
