<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/src/SiteScopeMode.php';
require_once $root.'/src/SiteScopedRuntimePolicy.php';
use VazinCMS\SiteScopedRuntimePolicy;

$check=static function(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('SCOPED_RUNTIME_POLICY_FAIL: '.$label);
};
$refuse=static function(callable $f,string $expected)use($check):void{
    try{$f();}catch(RuntimeException $e){
        $check($e->getMessage()===$expected,'unexpected refusal: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('unexpected acceptance: '.$expected);
};
$row=static function(string $key,string $type='module',
                     string $source='bundled',string $status='active'):array {
    return ['extension_key'=>$key,'extension_type'=>$type,
        'source'=>$source,'status'=>$status];
};
$check(SiteScopedRuntimePolicy::moduleAllowed($row('seo')),
       'audited SEO module not allowed');
$check(SiteScopedRuntimePolicy::moduleAllowed($row('content-migration')),
       'audited WXR module not allowed');
foreach(['telegram','publishing','travel','vazinpay','vazin-id','visa',
         'unknown','arbitrary'] as $key){
    $check(!SiteScopedRuntimePolicy::moduleAllowed($row($key)),
           'unreviewed module unexpectedly executable: '.$key);
}
$check(!SiteScopedRuntimePolicy::moduleAllowed($row('seo','module','runtime')),
       'runtime package impersonated bundled SEO');
$check(!SiteScopedRuntimePolicy::moduleAllowed($row('seo','module','bundled','inactive')),
       'inactive SEO module executed');
$check(!SiteScopedRuntimePolicy::moduleAllowed($row('seo','theme')),
       'theme impersonated module');
$check(SiteScopedRuntimePolicy::themeAllowed($row('vazin-default','theme')),
       'known default bundled theme not permitted');
$check(!SiteScopedRuntimePolicy::themeAllowed($row('vazin-default','theme','runtime')),
       'runtime theme impersonated bundled theme');
$check(!SiteScopedRuntimePolicy::themeAllowed($row('vazin-default','theme','bundled','broken')),
       'broken default theme permitted');
$check(!SiteScopedRuntimePolicy::themeAllowed($row('alternate-theme','theme')),
       'unknown tenant theme executed');
$check(!SiteScopedRuntimePolicy::extensionAllowed($row('telegram')),
       'global unreviewed module approved');

$legacy=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$legacy->exec('CREATE TABLE cms_extensions(id INTEGER PRIMARY KEY)');
$check(!SiteScopedRuntimePolicy::scopedMode($legacy),
       'no-tenant baseline unexpectedly treated as multi-tenant');

$partial=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$partial->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY)');
$partial->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY,site_id INTEGER)');
$refuse(static fn()=>SiteScopedRuntimePolicy::scopedMode($partial),
    'tenant_schema_missing_table');

$source=file_get_contents($root.'/src/ExtensionRuntime.php');
$check(is_string($source) &&
    str_contains($source,'SiteScopedRuntimePolicy::moduleAllowed($row)'),
    'ExtensionRuntime source policy not wired');
$source=file_get_contents($root.'/src/ExtensionManager.php');
$check(is_string($source) &&
    str_contains($source,'SiteScopedRuntimePolicy::extensionAllowed($row)'),
    'Global active extension truth not guarded');
echo "SITE_RUNTIME_POLICY=PASS audited_bundled_only,spoofed_packages_denied,no_global_execution\n";
