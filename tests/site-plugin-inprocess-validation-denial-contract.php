<?php
declare(strict_types=1);
/**
 * Direct-call RCE regression: a site-scoped app must never require an
 * unreviewed module bootstrap for validation, regardless of who invoked it.
 * Synthetic in-memory tenant schema and a synthetic module ONLY.
 */
$root=dirname(__DIR__);
$temp=sys_get_temp_dir().'/vazincms-plugin-inprocess-'.bin2hex(random_bytes(8));
if(!mkdir($temp,0700,true))throw new RuntimeException('fixture_dir');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('VAZINCMS_STORAGE_PATH='.$temp);
putenv('SESSION_SECURE=false');
require $root.'/src/bootstrap.php';

use VazinCMS\{Database,ExtensionManifest,ExtensionRuntime,SiteScopeMode};

$pdo=Database::connection();
$pdo->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO cms_sites VALUES(1),(2)');
$reflection=new ReflectionClass(SiteScopeMode::class);
$tables=$reflection->getReflectionConstant('TABLES')->getValue();
foreach($tables as $table){
    if(preg_match('/\A[a-z_]+\z/D',$table)!==1)throw new RuntimeException('unsafe_table');
    $pdo->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
}
if(!SiteScopeMode::active($pdo))throw new RuntimeException('qa_site_scope_not_active');
$bundle=$temp.'/unreviewed-module';
if(!mkdir($bundle,0700,true))throw new RuntimeException('qa_bundle_dir');
$marker=$temp.'/unexpected-inprocess-execution';
file_put_contents($bundle.'/vazin-extension.json',json_encode([
    'schema'=>1,'key'=>'unreviewed-module','type'=>'module','name'=>'QA Unreviewed',
    'version'=>'1.0.0',
    'requires'=>['php'=>'>=8.2.0','vazincms'=>'>=10.8.0','extensions'=>[]],
    'bootstrap'=>'bootstrap.php','default_status'=>'inactive'
],JSON_THROW_ON_ERROR));
file_put_contents($bundle.'/bootstrap.php',
    "<?php file_put_contents(".var_export($marker,true).",'RCE'); return static function(\\VazinCMS\\ModuleContext $".'m'."):void{};\n"
);
try{
    $manifest=ExtensionManifest::fromDirectory($bundle);
    $rejected=false;
    try{ExtensionRuntime::validateModule($manifest);}
    catch(RuntimeException $e){
        $rejected=$e->getMessage()==='tenant_plugin_validation_requires_isolated_control_plane';
    }
    if(!$rejected || is_file($marker)){
        throw new RuntimeException('UNREVIEWED_BOOTSTRAP_VALIDATION_EXECUTED_IN_PROCESS');
    }
    echo "TENANT_PLUGIN_INPROCESS_VALIDATION=PASS two_site_scoped_schema,direct_call_denied,bootstrap_not_required\n";
}finally{
    foreach([$bundle.'/bootstrap.php',$bundle.'/vazin-extension.json',$marker] as $file)@unlink($file);
    @rmdir($bundle);
    @rmdir($temp);
}
