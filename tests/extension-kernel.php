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
    $check(count($extensions) === 16, 'bundled extension discovery failed');
    $check(count(ExtensionManager::activeModules()) === 13, 'bundled modules are not active');
    $check(ExtensionManager::isActive('theme', 'vazin-default'), 'bundled theme is not active');
    $navigation=ExtensionManager::adminNavigation();
    $check(count($navigation) === 14, 'module-owned admin navigation failed');
    $check(in_array('/admin/content-migration',array_column($navigation,'path'),true),'Content migration navigation missing');
    $check(in_array('/admin/commerce',array_column($navigation,'path'),true),'Commerce navigation missing');
    $check(in_array('/admin/lms',array_column($navigation,'path'),true),'LMS navigation missing');
    $check(in_array('/admin/booking',array_column($navigation,'path'),true),'Booking navigation missing');
    $check(in_array('/admin/forms',array_column($navigation,'path'),true),'Forms navigation missing');
    $check(in_array('/admin/comments',array_column($navigation,'path'),true),'Comments navigation missing');
    $check(in_array('/admin/analytics',array_column($navigation,'path'),true),'Analytics navigation missing');
    $check(in_array('/admin/memberships',array_column($navigation,'path'),true),'Memberships navigation missing');
    $check(in_array('/admin/telegram/assistant',array_column($navigation,'path'),true),'Telegram assistant navigation missing');

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
    $moduleBootstrap=str_replace('<?php', "<?php file_put_contents('{$runtime}/runtime-module-executed','BAD');", $moduleBootstrap);
    $write($moduleRoot . '/bootstrap.php', $moduleBootstrap);
    $write($moduleRoot . '/assets/module.css', '.contract-module{display:block}');

    $themeRoot = $runtime . '/extensions/themes/contract-theme';
    $write($themeRoot . '/vazin-extension.json', json_encode([
        'schema' => 1, 'key' => 'contract-theme', 'type' => 'theme', 'name' => 'Contract Theme',
        'version' => '1.0.0', 'requires' => ['php' => '>=8.2.0', 'vazincms' => '>=10.8.0', 'extensions' => []],
        'views' => ['head' => 'views/head.php', 'foot' => 'views/foot.php'], 'default_status' => 'inactive',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $write($themeRoot . '/views/head.php', "<?php file_put_contents('{$runtime}/runtime-theme-executed','BAD'); ?><!doctype html><html><body><main>");
    $write($themeRoot . '/views/foot.php', '</main></body></html>');

    ExtensionManager::reset();
    ExtensionManager::synchronize();
    // Candidate isolation hard gate: previously these runtime module/theme
    // fixtures were activated and their PHP executed in the CMS process.
    // They must now remain inert and require a separately signed isolated
    // control-plane execution path instead.
    $blockedActivation=static function(string $key):void {
        try { ExtensionManager::activate($key); }
        catch(RuntimeException $e){
            if($e->getMessage()==='plugin_inprocess_activation_denied')return;
            throw $e;
        }
        throw new RuntimeException('runtime package activated in CMS process');
    };
    $blockedActivation('contract-module');
    $blockedActivation('contract-theme');
    $check(!ExtensionManager::isActive('module','contract-module'),
           'runtime module appeared active despite denial');
    $check(!ExtensionManager::isActive('theme','contract-theme'),
           'runtime PHP theme appeared active despite denial');
    $manifest=VazinCMS\ExtensionManifest::fromDirectory($moduleRoot);
    try{
        ExtensionRuntime::validateModule($manifest);
        throw new RuntimeException('runtime bootstrap was validated in process');
    }catch(RuntimeException $e){
        $check($e->getMessage()==='plugin_inprocess_untrusted_package_denied',
               'unexpected in-process validation denial');
    }
    $check(!ExtensionRuntime::dispatch('GET','/extension-contract'),
           'runtime route executed in CMS');
    $check(ExtensionRuntime::emit('contract.event',['value'=>'blocked'])===[],
           'runtime hook executed in CMS');
    $check(!isset($GLOBALS['vazincms_contract_hook']),
           'runtime hook side effect executed');
    $check(ThemeManager::previewTheme('contract-theme')===null,
           'unsafe runtime theme previewed');
    $templates=ThemeManager::templates('cms-page');
    $check(!str_contains(implode('|',$templates['paths']),'contract-theme'),
           'unsafe runtime PHP theme evaluated');
    $check(str_contains(str_replace('\\','/', $templates['paths'][1]),'/views/public/'),
           'core trusted theme fallback unavailable');
    // Model a preexisting active runtime row (there is at least one such
    // row in the real S17 registry, observed READ-ONLY). Even a legacy
    // active bit must not permit a bootstrap, hook, route or PHP theme.
    $pdo->exec("UPDATE cms_extensions SET status='active' WHERE extension_key IN('contract-module','contract-theme')");
    $pdo->exec("UPDATE cms_extensions SET status='inactive' WHERE extension_key='vazin-default'");
    ExtensionManager::reset();
    $check(!ExtensionRuntime::dispatch('GET','/extension-contract'),
           'preexisting active runtime module dispatched');
    $check(ExtensionRuntime::emit('contract.event',['value'=>'blocked'])===[],
           'preexisting active runtime hook dispatched');
    $check(ExtensionManager::activeExtension('module','contract-module')===null,
           'runtime module active endpoint not rejected');
    $check(!ExtensionManager::isActive('module','contract-module'),
           'runtime active status bypassed package policy');
    $check(ExtensionManager::activeTheme()===null,
           'active runtime PHP theme admitted');
    $fallback=ThemeManager::templates('cms-page');
    $check(!str_contains(implode('|',$fallback['paths']),'contract-theme'),
           'untrusted theme path reached renderer');
    $check(!is_file($runtime.'/runtime-module-executed') &&
           !is_file($runtime.'/runtime-theme-executed'),
           'preexisting runtime PHP executed in CMS');
    $pdo->exec("UPDATE cms_extensions SET status='inactive' WHERE extension_key IN('contract-module','contract-theme')");
    $pdo->exec("UPDATE cms_extensions SET status='active' WHERE extension_key='vazin-default'");
    ExtensionManager::reset();
    ExtensionManager::activate('vazin-default');
    echo "Extension in-process runtime packages denied; bundled modules remain available\n";

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
        $zip->addFromString('archive-module/bootstrap.php', "<?php file_put_contents('{$runtime}/install-payload-executed','UNTRUSTED'); return static function(\\VazinCMS\\ModuleContext \$module):void{};\n");
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
        $zip->addFromString('archive-module/bootstrap.php', "<?php file_put_contents('{$runtime}/install-payload-executed','UNTRUSTED'); return static function(\\VazinCMS\\ModuleContext \$module):void{};\n");
        $zip->close();
        $upgraded = ExtensionManager::installArchive($upgrade);
        $check($upgraded['version'] === '1.1.0' && $upgraded['status'] === 'inactive', 'atomic extension upgrade failed');
        $check(!is_file($runtime.'/install-payload-executed'),
            'untrusted uploaded PHP was executed during static package installation');
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
