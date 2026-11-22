<?php
declare(strict_types=1);
/** Real OS permission tests. No plugin, daemon, Docker CLI or receipt use.
 * Invoke as a genuinely unprivileged UID; root is NOT accepted evidence.
 */
require dirname(__DIR__).'/src/SiteScopedExtensionSandbox.php';
use VazinCMS\SiteScopedExtensionSandbox as Sandbox;
$checks=[];
$assert=static function(bool $ok,string $name)use(&$checks):void{
    if(!$ok)throw new RuntimeException('NONROOT_SNAPSHOT_FAIL:'.$name);
    $checks[$name]=true;
};
$assert(function_exists('posix_geteuid')&&posix_geteuid()>0,'actual_nonroot_euid');
$status=(string)file_get_contents('/proc/self/status');
preg_match('/^CapEff:\s*([a-f0-9]+)$/mi',$status,$caps);
$assert(isset($caps[1])&&trim($caps[1],'0')==='','zero_effective_capabilities');
$pin=new ReflectionMethod(Sandbox::class,'pinnedPackage');
$remove=new ReflectionMethod(Sandbox::class,'removePinnedPackage');
$denied=static function(callable $fn,string $code,string $name)use($assert):void{
    try{$fn();}catch(RuntimeException $failure){
        $assert($failure->getMessage()===$code,$name);return;
    }
    throw new RuntimeException('NONROOT_SNAPSHOT_UNEXPECTED_ALLOW:'.$name);
};
$base=sys_get_temp_dir().'/vazincms-nonroot-case-'.bin2hex(random_bytes(8));
$source=$base.'/source';
mkdir($source.'/core/deep',0700,true);
file_put_contents($source.'/bootstrap.php',"<?php // inert bytes, not evaluated\n");
file_put_contents($source.'/core/deep/a.txt','snapshot data');
mkdir($source.'/empty',0700);
$digest=Sandbox::packageDigest($source);
$mode=static function(string $path):int{clearstatcache(true,$path);return (int)fileperms($path)&0777;};
// Error suppression is local to absent-path checks, never to test outcomes.
$absent=static function(string $path):bool{clearstatcache(true,$path);return @lstat($path)===false;};
$known=glob(sys_get_temp_dir().'/vazincms-sbox-pin-*')?:[];sort($known);
$pinned=$pin->invoke(null,$source,$digest);
$assert(fileowner($pinned)===posix_geteuid(),'snapshot_owned_by_actual_nonroot_user');
$assert($mode(dirname($pinned))===0700,'private_snapshot_parent_0700');
$assert($mode($pinned)===0555,'snapshot_root_0555_during_execution');
$assert($mode($pinned.'/core/deep')===0555,'nested_snapshot_directory_0555');
$assert($mode($pinned.'/bootstrap.php')===0444,'snapshot_entrypoint_0444');
$assert($mode($pinned.'/core/deep/a.txt')===0444,'nested_snapshot_file_0444');
$assert(hash_equals($digest,Sandbox::packageDigest($pinned)),'all_snapshot_bytes_match_pin');
$remove->invoke(null,$pinned);
$assert($absent($pinned),'nonroot_cleanup_removes_complete_snapshot');
$assert($absent(dirname($pinned)),'nonroot_cleanup_removes_private_parent');
$assert(hash_equals($digest,Sandbox::packageDigest($source)),'original_package_not_modified');
$assert(is_dir($source.'/empty'),'original_empty_directory_preserved');

// Cleanup must not accept an arbitrary caller-supplied directory.
$foreign=$base.'/foreign';mkdir($foreign,0700);file_put_contents($foreign.'/keep.txt','unrelated');
$denied(static fn()=>$remove->invoke(null,$foreign),'tenant_sandbox_snapshot_cleanup_target_invalid','unregistered_path_refused');
$assert(file_get_contents($foreign.'/keep.txt')==='unrelated','foreign_directory_and_file_preserved');
$assert($mode($foreign)===0700,'foreign_permissions_unchanged');

// Directories and files outside the snapshot must not be traversed, chmodded
// or deleted through symlinks or hard links inserted in a private QA copy.
$outside=$base.'/outside';mkdir($outside,0700);
file_put_contents($outside.'/keep.txt','outside sentinel');chmod($outside.'/keep.txt',0440);
$outsideHash=hash_file('sha256',$outside.'/keep.txt');
$pinned=$pin->invoke(null,$source,$digest);
chmod($pinned,0700);
$assert(symlink($outside.'/keep.txt',$pinned.'/file-link'),'real_file_symlink_created');
$assert(symlink($outside,$pinned.'/directory-link'),'real_directory_symlink_created');
$assert(symlink($outside.'/missing',$pinned.'/dangling-link'),'real_dangling_symlink_created');
$assert(link($outside.'/keep.txt',$pinned.'/hard-link'),'real_hard_link_created');
$assert(posix_mkfifo($pinned.'/fifo',0600),'real_special_file_created');
chmod($pinned,0555);
$remove->invoke(null,$pinned);
$assert($absent($pinned)&&$absent(dirname($pinned)),'links_and_special_file_removed_without_following');
$assert(is_file($outside.'/keep.txt'),'external_symlink_hardlink_target_survives');
$assert(hash_file('sha256',$outside.'/keep.txt')===$outsideHash,'external_file_bytes_unchanged');
$assert($mode($outside.'/keep.txt')===0440,'external_file_mode_not_changed_by_chmod');
$assert($mode($outside)===0700,'external_directory_mode_unchanged');

// A replaced private parent must be detected before touching its contents.
$pinned=$pin->invoke(null,$source,$digest);$parent=dirname($pinned);$parked=$parent.'-held';
rename($parent,$parked);mkdir($parent,0700);file_put_contents($parent.'/keep.txt','replacement');
$denied(static fn()=>$remove->invoke(null,$pinned),'tenant_sandbox_snapshot_cleanup_target_changed','replaced_parent_inode_refused');
$assert(file_get_contents($parent.'/keep.txt')==='replacement','replacement_directory_not_touched');
$assert(is_file($parked.'/package/bootstrap.php'),'original_snapshot_preserved_on_identity_failure');
unlink($parent.'/keep.txt');rmdir($parent);rename($parked,$parent);
$remove->invoke(null,$pinned);
$assert($absent($parent),'cleanup_succeeds_after_explicit_test_identity_restore');

// Unexpected siblings prove incomplete cleanup is signaled, never advertised
// as success, and do not authorize broad recursive deletion.
$pinned=$pin->invoke(null,$source,$digest);$parent=dirname($pinned);
file_put_contents($parent.'/unexpected.txt','do not delete automatically');
$denied(static fn()=>$remove->invoke(null,$pinned),'tenant_sandbox_snapshot_cleanup_unverified','partial_cleanup_is_explicit_failure');
$assert(is_file($parent.'/unexpected.txt'),'unexpected_sibling_not_deleted');
$assert($absent($pinned),'owned_package_removed_before_parent_failure');
unlink($parent.'/unexpected.txt');$remove->invoke(null,$pinned);
$assert($absent($parent),'explicit_test_recovery_of_failed_parent_cleanup');

// Failure while creating a snapshot must also clean a partial directory.
$denied(static fn()=>$pin->invoke(null,$source,str_repeat('f',64)),
    'tenant_sandbox_snapshot_untrusted','stale_package_pin_rejected');
$after=glob(sys_get_temp_dir().'/vazincms-sbox-pin-*')?:[];sort($after);
$assert($after===$known,'failed_digest_snapshot_left_no_directories');
symlink($outside,$source.'/outside-link');
$denied(static fn()=>$pin->invoke(null,$source,$digest),
    'tenant_sandbox_snapshot_untrusted','source_symlink_rejected_without_traversal');
unlink($source.'/outside-link');
$after=glob(sys_get_temp_dir().'/vazincms-sbox-pin-*')?:[];sort($after);
$assert($after===$known,'failed_symlink_snapshot_left_no_directories');
$assert(hash_equals($digest,Sandbox::packageDigest($source)),'source_digest_still_exact_after_negative_cases');
$denied(static fn()=>Sandbox::rootlessEnvironment('/var/run/docker.sock',$base),
    'plugin_worker_rootless_socket_required','rootful_socket_still_refused_without_contact');

// Remove only this script's fixture after all preservation assertions.
$erase=static function(string $path)use(&$erase):void{
    if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
    chmod($path,0700);
    foreach(scandir($path)?:[] as $child)if($child!=='.'&&$child!=='..')$erase($path.'/'.$child);
    rmdir($path);
};
$erase($base);
$assert($absent($base),'test_fixture_removed');
echo json_encode([
 'result'=>'PASS','suite'=>'real_nonroot_snapshot_filesystem',
 'assertion_count'=>count($checks),'checks'=>$checks,'real_euid'=>posix_geteuid(),
 'capabilities_effective'=>$caps[1],'php_version'=>PHP_VERSION,
 'plugin_executed'=>false,'rootless_daemon_tested'=>false,
 'independent_plugin_acceptance'=>false,'signing_performed'=>false,
 'production_access'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
