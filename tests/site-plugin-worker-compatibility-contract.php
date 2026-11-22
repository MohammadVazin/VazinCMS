<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteScopedExtensionSandbox.php';
require dirname(__DIR__).'/src/SiteScopedPluginCompatibility.php';
use VazinCMS\SiteScopedPluginCompatibility as Compatibility;
$need=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('PLUGIN_WORKER_COMPAT_QA_FAIL:'.$why);
};
$dir=sys_get_temp_dir().'/vazincms-worker-compat-'.bin2hex(random_bytes(8));
$need(mkdir($dir,0700,true),'fixture_root');
$minimal=$dir.'/minimal';$native=$dir.'/native';
$need(mkdir($minimal,0700),'minimal_root');
$need(mkdir($native,0700),'native_root');
try{
    file_put_contents($minimal.'/bootstrap.php',"<?php echo 'isolated';\n");
    $ok=Compatibility::inspect($minimal);
    $need($ok['compatible']===true && $ok['missing_capabilities']===[],
        'minimal_worker_bundle_not_compatible');

    file_put_contents($native.'/bootstrap.php',<<<'PHP'
<?php
use VazinCMS\ModuleContext;
function registerModule(ModuleContext $module):void {
    $module->get('/admin/example',static fn()=>null);
}
PHP
);
    file_put_contents($native.'/Controller.php',<<<'PHP'
<?php
// Database::connection() and curl_exec($x) in comments must be ignored.
$example='SecretStore::open() is documentation, not a call';
Auth::user();
Access::allowed([], 'content');
Database::connection();
RuntimePaths::storage();
SiteContext::resolve(null);
SecretStore::open('');
Security::csrf();
MediaStorage::driver();
curl_init('https://example.invalid');
curl_exec($handle);
file_put_contents('/tmp/example','x');
PHP
);
    $blocked=Compatibility::inspect($native);
    $required=[
        'cms_module_context_routes','cms_authentication','cms_authorization',
        'cms_database','cms_runtime_paths','cms_site_context',
        'cms_secret_store','cms_security_csrf','cms_media_storage',
        'outbound_https','host_filesystem_writes'
    ];
    sort($required,SORT_STRING);
    $need($blocked['compatible']===false,'native_api_bundle_accepted');
    $need($blocked['missing_capabilities']===$required,
        'missing_capability_list_incomplete_or_false_positive:'.implode(',',$blocked['missing_capabilities']));
    echo "PLUGIN_WORKER_COMPATIBILITY=PASS minimal_bundle_accepted,cms_api_network_secret_media_denied,comments_strings_ignored\n";
}finally{
    $iterator=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($iterator as $item){
        if($item->isDir() && !$item->isLink())@rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($dir);
}
