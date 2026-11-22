<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteUniqueKeys.php';
use VazinCMS\SiteUniqueKeys;

function assertUnique(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException('SITE_UNIQUE_KEYS_FAIL: '.$label);
}
function refusal(callable $fn,string $expected): void
{
    try{$fn();}catch(RuntimeException $error){
        assertUnique($error->getMessage()===$expected,
            'wrong error '.$error->getMessage().' expected '.$expected);
        return;
    }
    throw new RuntimeException('Expected refusal '.$expected);
}

foreach(['legacy','scoped'] as $mode){
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($mode==='legacy'){
        $pdo->exec("CREATE TABLE cms_settings(setting_key TEXT PRIMARY KEY,site_id INTEGER,setting_value TEXT)");
        $pdo->exec("CREATE TABLE cms_redirects(id INTEGER PRIMARY KEY,site_id INTEGER,source_path TEXT UNIQUE,target_url TEXT)");
        assertUnique(SiteUniqueKeys::target($pdo,'cms_settings','setting_key')==='(setting_key)','legacy setting conflict');
        assertUnique(SiteUniqueKeys::target($pdo,'cms_redirects','source_path')==='(source_path)','legacy redirect conflict');
    }else{
        $pdo->exec("CREATE TABLE cms_settings(setting_key TEXT,site_id INTEGER NOT NULL,setting_value TEXT,PRIMARY KEY(site_id,setting_key))");
        $pdo->exec("CREATE TABLE cms_redirects(id INTEGER PRIMARY KEY,site_id INTEGER NOT NULL,source_path TEXT,target_url TEXT,UNIQUE(site_id,source_path))");
        assertUnique(SiteUniqueKeys::target($pdo,'cms_settings','setting_key')==='(site_id,setting_key)','scoped setting conflict');
        assertUnique(SiteUniqueKeys::target($pdo,'cms_redirects','source_path')==='(site_id,source_path)','scoped redirect conflict');
    }
    refusal(static fn()=>SiteUniqueKeys::target($pdo,'users','password_hash'),'tenant_unique_target_unsupported');
}
$pdo->exec('CREATE UNIQUE INDEX idx_settings_old_global ON cms_settings(setting_key)');
refusal(static fn()=>SiteUniqueKeys::target($pdo,'cms_settings','setting_key'),
    'tenant_mixed_unique_constraints');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE cms_settings(site_id INTEGER,setting_key TEXT,setting_value TEXT)');
refusal(static fn()=>SiteUniqueKeys::target($pdo,'cms_settings','setting_key'),
    'tenant_unique_constraint_missing');
echo "SITE_UNIQUE_CONFLICT_TARGET=PASS legacy,composite,mixed_rejected,missing_rejected\n";
