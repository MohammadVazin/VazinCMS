<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir() . '/vazincms-extension-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('VAZINCMS_STORAGE_PATH=' . $runtime);
putenv('SESSION_SECURE=false');
require $root . '/src/bootstrap.php';

use VazinCMS\{Database,ExtensionAsset,ExtensionManager,ExtensionRuntime,LocalizedTemplate,ThemeManager,UiLocale};

$check = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$write = static function(string $path, string $contents): void {
    $parent = dirname($path);
    if (!is_dir($parent)) mkdir($parent, 0700, true);
    if (file_put_contents($path, $contents, LOCK_EX) === false) throw new RuntimeException('test fixture write failed');
};
$cleanup = static function(string $path) use (&$cleanup): void {
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) $cleanup($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($path);
};

try {
    $pdo = Database::connection();
    $pdo->exec((string) file_get_contents($root . '/database/schema-sqlite.sql'));
    $pdo->exec((string) file_get_contents($root . '/database/migrations/4.1.0-sqlite.sql'));
    $pdo->exec((string) file_get_contents($root . '/database/migrations/5.0.1-sqlite.sql'));
    $pdo->exec((string) file_get_contents($root . '/database/migrations/10.7.0-sqlite.sql'));
    $pdo->exec((string) file_get_contents($root . '/database/migrations/10.8.0-sqlite.sql'));

    ExtensionManager::reset();
    $extensions = ExtensionManager::all();
    $check(count($extensions) === 9, 'bundled extension discovery failed');
    $check(count(ExtensionManager::activeModules()) === 8, 'bundled modules are not active');
    $check(ExtensionManager::isActive('theme', 'vazin-default'), 'bundled theme is not active');
    $navigation=ExtensionManager::adminNavigation();
    $check(count($navigation) === 13, 'module-owned admin navigation failed');
    $check(in_array('/admin/content-migration',array_column($navigation,'path'),true),'Content migration navigation missing');
    $check(in_array('/admin/telegram/assistant',array_column($navigation,'path'),true),'Telegram assistant navigation missing');
    $check(in_array('/admin/travel-alerts',array_column($navigation,'path'),true),'Travel/Visa Telegram alert navigation missing');
    $check(in_array('/admin/agency-inquiries',array_column($navigation,'path'),true),'Travel agency inbox navigation missing');

    $moduleRoot = $runtime . '/extensions/modules/contract-module';
    $write($moduleRoot . '/vazin-extension.json', json_encode([
        'schema' => 1, 'key' => 'contract-module', 'type' => 'module', 'name' => 'Contract Module',
        'version' => '1.0.0', 'requires' => ['php' => '>=8.2.0', 'vazincms' => '>=10.8.0', 'extensions' => []],
        'bootstrap' => 'bootstrap.php', 'default_status' => 'inactive',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $moduleBootstrap = <<<'PHP'
<?php
use VazinCMS\ModuleContext;
return static function(ModuleContext $module):void{
    $module->get('/extension-contract',static function(array $matches):void{echo 'contract-ok';});
    $module->on('contract.event',static function(array $payload):void{$GLOBALS['vazincms_contract_hook']=$payload['value']??null;});
};
PHP;
    $write($moduleRoot . '/bootstrap.php', $moduleBootstrap);
    $write($moduleRoot . '/assets/module.css', '.contract-module{display:block}');

    $themeRoot = $runtime . '/extensions/themes/contract-theme';
    $write($themeRoot . '/vazin-extension.json', json_encode([
        'schema' => 1, 'key' => 'contract-theme', 'type' => 'theme', 'name' => 'Contract Theme',
        'version' => '1.0.0', 'requires' => ['php' => '>=8.2.0', 'vazincms' => '>=10.8.0', 'extensions' => []],
        'views' => ['head' => 'views/head.php', 'foot' => 'views/foot.php'], 'default_status' => 'inactive',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $write($themeRoot . '/views/head.php', '<!doctype html><html><body><main>');
    $write($themeRoot . '/views/foot.php', '</main></body></html>');

    ExtensionManager::reset();
    ExtensionManager::synchronize();
    ExtensionManager::activate('contract-module');
    ob_start();
    $dispatched = ExtensionRuntime::dispatch('GET', '/extension-contract');
    $output = (string) ob_get_clean();
    $check($dispatched && $output === 'contract-ok', 'custom module route was not dispatched');
    ExtensionRuntime::emit('contract.event', ['value' => 'hook-ok']);
    $check(($GLOBALS['vazincms_contract_hook'] ?? null) === 'hook-ok', 'custom module hook was not dispatched');
    $check(ExtensionAsset::url('module','contract-module','module.css')==='/module-assets/contract-module/module.css','module asset URL failed');
    ob_start();ExtensionAsset::serve('module','contract-module','module.css');$assetOutput=(string)ob_get_clean();
    $check(str_contains($assetOutput,'.contract-module'),'module asset serving failed');
    ExtensionManager::deactivate('contract-module');
    $check(!ExtensionRuntime::dispatch('GET', '/extension-contract'), 'deactivated module still owns its route');
    ExtensionManager::activate('contract-module');
    $write($moduleRoot . '/bootstrap.php', $moduleBootstrap . "\n// changed outside installer\n");
    ExtensionManager::reset();
    $previousErrorLog=(string)ini_get('error_log');ini_set('error_log',$runtime.'/expected-extension-error.log');
    try{$tamperedDispatched=ExtensionRuntime::dispatch('GET', '/extension-contract');}finally{ini_set('error_log',$previousErrorLog);}
    $check(!$tamperedDispatched, 'tampered module was executed');
    $check((string)$pdo->query("SELECT status FROM cms_extensions WHERE extension_key='contract-module'")->fetchColumn()==='broken', 'tampered module was not quarantined');

    ExtensionManager::activate('contract-theme');
    $templates = ThemeManager::templates('cms-page');
    $check(str_contains($templates['paths'][0], 'contract-theme'), 'custom theme head was not selected');
    $check(str_contains($templates['paths'][2], 'contract-theme'), 'custom theme foot was not selected');
    $check(str_contains(str_replace('\\', '/', $templates['paths'][1]), '/views/public/'), 'theme fallback hierarchy failed');
    UiLocale::boot('fa');
    $html = UiLocale::html(LocalizedTemplate::render($templates['paths'], [
        'locale' => 'fa', 'page' => null, 'siteName' => 'Contract Site', 'appVersion' => '10.9.0',
        'settings' => [], 'profile' => 'corporate', 'meta' => [], 'menu' => [], 'viewName' => 'cms-page',
    ], $templates['allowed_roots']));
    $check(str_contains($html, 'Contract Site'), 'theme and core view composition failed');
    ExtensionManager::activate('vazin-default');

    ExtensionManager::archive('contract-module');
    ExtensionManager::archive('contract-theme');
    $expectedRemoved = 2;
    if (class_exists(ZipArchive::class)) {
        $archive = $runtime . '/archive-module.zip';
        $zip = new ZipArchive();
        $check($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'zip fixture creation failed');
        $zip->addFromString('archive-module/vazin-extension.json', json_encode([
            'schema' => 1, 'key' => 'archive-module', 'type' => 'module', 'name' => 'Archive Module',
            'version' => '1.0.0', 'requires' => ['php' => '>=8.2.0', 'vazincms' => '>=10.8.0', 'extensions' => []],
            'bootstrap' => 'bootstrap.php', 'default_status' => 'inactive',
        ], JSON_UNESCAPED_SLASHES));
        $zip->addFromString('archive-module/bootstrap.php', "<?php return static function(\\VazinCMS\\ModuleContext \$module):void{};\n");
        $zip->close();
        $installed = ExtensionManager::installArchive($archive);
        $check($installed['status'] === 'inactive', 'zip package must install inactive');
        $upgrade = $runtime . '/archive-module-1.1.0.zip';
        $zip = new ZipArchive();
        $zip->open($upgrade, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('archive-module/vazin-extension.json', json_encode([
            'schema' => 1, 'key' => 'archive-module', 'type' => 'module', 'name' => 'Archive Module',
            'version' => '1.1.0', 'requires' => ['php' => '>=8.2.0', 'vazincms' => '>=10.8.0', 'extensions' => []],
            'bootstrap' => 'bootstrap.php', 'default_status' => 'inactive',
        ], JSON_UNESCAPED_SLASHES));
        $zip->addFromString('archive-module/bootstrap.php', "<?php return static function(\\VazinCMS\\ModuleContext \$module):void{};\n");
        $zip->close();
        $upgraded = ExtensionManager::installArchive($upgrade);
        $check($upgraded['version'] === '1.1.0' && $upgraded['status'] === 'inactive', 'atomic extension upgrade failed');
        $check((glob($runtime.'/extensions/.trash/module-archive-module-1.0.0-*')?:[])!==[], 'previous extension version was not retained');
        ExtensionManager::archive('archive-module');
        $expectedRemoved++;

        $unsafe = $runtime . '/unsafe-module.zip';
        $zip = new ZipArchive();
        $zip->open($unsafe, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('vazin-extension.json', json_encode([
            'schema' => 1, 'key' => 'unsafe-module', 'type' => 'module', 'name' => 'Unsafe Module',
            'version' => '1.0.0', 'requires' => ['php' => '>=8.2.0', 'vazincms' => '>=10.8.0', 'extensions' => []],
            'bootstrap' => 'bootstrap.php', 'default_status' => 'inactive',
        ], JSON_UNESCAPED_SLASHES));
        $zip->addFromString('bootstrap.php', "<?php return static function(\\VazinCMS\\ModuleContext \$module):void{};\n");
        $zip->addFromString('../extension-escape.php', '<?php');
        $zip->close();
        $rejected = false;
        try { ExtensionManager::installArchive($unsafe); } catch (Throwable) { $rejected = true; }
        $check($rejected, 'zip traversal package was accepted');
    }
    $removed = (int) $pdo->query("SELECT COUNT(*) FROM cms_extensions WHERE status='removed'")->fetchColumn();
    $check($removed === $expectedRemoved, 'recoverable extension archive failed');
    echo "Extension kernel contract: OK\n";
} finally {
    $cleanup($runtime);
}
