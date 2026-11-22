<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/src/SiteScopedExtensionSandbox.php';
use VazinCMS\SiteScopedExtensionSandbox as Sandbox;
$assert=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('SANDBOX_PIN_CONTRACT_FAIL:'.$why);
};
$dir=sys_get_temp_dir().'/vazincms-pin-contract-'.bin2hex(random_bytes(8));
$assert(mkdir($dir,0700,true),'fixture_dir');
$package=$dir.'/package';
$assert(mkdir($package,0700),'package_dir');
$assert(mkdir($package.'/core',0700),'package_nested_dir');
$input=$package.'/bootstrap.php';
$dependency=$package.'/core/Core.php';
$big=$package.'/oversize.php';
$pinned=null;
try {
    file_put_contents($input,"<?php require __DIR__.'/core/Core.php'; echo 'reviewed';\n");
    file_put_contents($dependency,"<?php class PinnedCoreFixture {}\n");
    $hash=Sandbox::packageDigest($package);
    $method=new ReflectionMethod(Sandbox::class,'pinnedPackage');
    $pinned=$method->invoke(null,$package,$hash);
    $assert(is_string($pinned) && is_dir($pinned),'snapshot_absent');
    $assert(realpath($pinned)!==realpath($package),'original_directory_mounted');
    $assert(hash_equals($hash,Sandbox::packageDigest($pinned)),'snapshot_tree_digest');
    $assert((fileperms(dirname($pinned)) & 0777)===0700,'snapshot_folder_mode');
    $assert((fileperms($pinned) & 0777)===0555,'snapshot_package_mode');
    $assert((fileperms($pinned.'/bootstrap.php') & 0777)===0444,'snapshot_file_mode');
    $assert((fileperms($pinned.'/core') & 0777)===0555,'snapshot_nested_directory_mode');
    $assert((fileperms($pinned.'/core/Core.php') & 0777)===0444,'snapshot_nested_file_mode');
    file_put_contents($input,"<?php echo 'unreviewed mutation';\n");
    $assert(!hash_equals($hash,Sandbox::packageDigest($package)),'source_tree_not_modified');
    $assert(hash_equals($hash,Sandbox::packageDigest($pinned)),
        'pinned_package_changed_with_original');
    try {
        $method->invoke(null,$package,$hash);
        throw new RuntimeException('SANDBOX_PIN_CONTRACT_FAIL:stale_digest_allowed');
    } catch (RuntimeException $e) {
        $assert($e->getMessage()==='tenant_sandbox_snapshot_untrusted',
            'wrong_stale_digest_rejection:'.$e->getMessage());
    }
    file_put_contents($big,str_repeat('x',16777217));
    try {
        $method->invoke(null,$package,$hash);
        throw new RuntimeException('SANDBOX_PIN_CONTRACT_FAIL:oversized_allowed');
    } catch (RuntimeException $e) {
        $assert($e->getMessage()==='tenant_sandbox_snapshot_untrusted',
            'wrong_oversize_rejection:'.$e->getMessage());
    }
    $source=file_get_contents($root.'/src/SiteScopedExtensionSandbox.php');
    $assert(str_contains($source,'runPinned($argv,$pinned,$timeoutSeconds,$environment)') &&
        str_contains($source,'self::removePinnedPackage($pinned)') &&
        str_contains($source,'$mounts!==1'),
        'pinned_package_not_wired_to_runner');
    echo "SITE_SANDBOX_PINNED_PACKAGE=PASS full_tree_snapshot,readonly_0555,files_0444,digest_stable,source_mutation_denied,oversize_denied\n";
} finally {
    if(is_string($pinned)){
        $remove=new ReflectionMethod(Sandbox::class,'removePinnedPackage');
        $remove->invoke(null,$pinned);
    }
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
