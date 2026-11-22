<?php
declare(strict_types=1);

/**
 * Official migration runner regression contract for comment-only SQL.
 * The real end-to-end PDO_PGSQL execution runs separately on isolated PG17.
 */
$root=dirname(__DIR__);
$files=glob($root.'/database/migrations/*-pgsql.sql')?:[];
$assert=static function(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('MIGRATION_COMMENT_ONLY_FAIL: '.$why);
};
$assert(count($files)===78,'expected 69 historical plus 9 staged PG migrations');
$found=[];
$registryOmissions=[];
foreach($files as $file) {
    $data=file_get_contents($file);
    $assert(is_string($data)&&$data!=='','unreadable migration');
    $hasSql=false;
    foreach(preg_split('/\r\n|\r|\n/',$data)?:[] as $line) {
        $line=ltrim($line);
        if($line!==''&&!str_starts_with($line,'--')) {
            $hasSql=true;
            break;
        }
    }
    if(!$hasSql)$found[]=basename($file);
    // Staged 10.31.x versions are stamped by migrate.php, not by SQL.
    // Preserve the five historical standalone-registry exceptions.
    if(!str_starts_with(basename($file),'10.31.') &&
       !str_contains($data,'schema_migrations')){
        $registryOmissions[]=basename($file);
    }
}
$assert($found===['10.30.1-pgsql.sql'],
        'comment-only migration classification drift: '.implode(',',$found));
sort($registryOmissions,SORT_STRING);
$assert($registryOmissions===[
    '10.30.0-pgsql.sql','10.30.1-pgsql.sql','5.0.1-pgsql.sql',
    '5.2.0-pgsql.sql','9.0.0-pgsql.sql'
], 'unmarked migration file set changed');
$source=file_get_contents($root.'/scripts/migrate.php');
$assert(is_string($source) &&
    str_contains($source,'if ($hasExecutableStatement) $pdo->exec($sql);') &&
    str_contains($source,'$mark->execute') &&
    strpos($source,'if ($hasExecutableStatement) $pdo->exec($sql);') <
      strpos($source,'$mark->execute'),
    'migration runner might execute comment-only text or skip registry marker');
echo "MIGRATION_COMMENT_ONLY_CONTRACT=PASS 69_legacy_plus_9_staged,1_noop,5_legacy_unmarked,runner_marks_all\n";
