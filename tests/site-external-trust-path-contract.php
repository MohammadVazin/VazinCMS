<?php
declare(strict_types=1);
/** Synthetic path-metadata QA, no keys, signatures, or release actions. */
require dirname(__DIR__).'/src/SiteReleaseEvidenceVerifier.php';
use VazinCMS\SiteReleaseEvidenceVerifier as Verify;
$dir=sys_get_temp_dir().'/vazincms-trust-layout-'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700,true))throw new RuntimeException('fixture_missing');
$key=$dir.'/public-key.txt';$link=$dir.'/linked.txt';$alias=$dir.'-link';
$check=static function(bool $x,string $why):void{
    if(!$x)throw new RuntimeException('TRUST_PATH_CONTRACT_FAIL:'.$why);
};
$directory=new ReflectionMethod(Verify::class,'externalDirectorySafe');
$file=new ReflectionMethod(Verify::class,'trustedFileSafe');
try {
    file_put_contents($key,str_repeat('a',64));
    chmod($key,0600);
    $check($directory->invoke(null,$dir)===true,'private_root_dir_not_recognized');
    $check($file->invoke(null,$key,128)===true,'private_file_not_recognized');
    chmod($dir,0777);
    $check($directory->invoke(null,$dir)===false,'world_writable_dir_accepted');
    chmod($dir,0700);
    chmod($key,0666);
    $check($file->invoke(null,$key,128)===false,'world_writable_file_accepted');
    chmod($key,0600);
    symlink($key,$link);
    $check($file->invoke(null,$link,128)===false,'symlink_file_accepted');
    symlink($dir,$alias);
    $check($directory->invoke(null,$alias)===false,'symlink_dir_accepted');
    file_put_contents($key,str_repeat('a',300));
    $check($file->invoke(null,$key,128)===false,'oversize_key_accepted');
    $check(!$file->invoke(null,$dir.'/missing',128),'missing_key_accepted');
    echo "SITE_EXTERNAL_TRUST_PATH=PASS root_private_dir,mode_checks,symlink_denied,oversize_denied,no_signing\n";
} finally {
    @unlink($alias);@unlink($link);@unlink($key);@rmdir($dir);
}
