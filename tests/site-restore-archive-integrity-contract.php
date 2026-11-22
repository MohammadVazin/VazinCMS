<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteRestoreArchiveIntegrity.php';
use VazinCMS\SiteRestoreArchiveIntegrity as Audit;
$root=sys_get_temp_dir().'/vazincms-restore-inventory-'.bin2hex(random_bytes(6));
if(!mkdir($root.'/media/tenant-a',0700,true))throw new RuntimeException('fixture_mkdir');
try{
    $db=$root.'/synthetic.sqlite';
    $img=$root.'/media/tenant-a/photo.jpg';
    file_put_contents($db,'synthetic SQlite database bytes; not a real account');
    file_put_contents($img,'synthetic media bytes; not a customer image');
    $data=['schema'=>'vazincms-restore-check/v1','files'=>[
        ['path'=>'synthetic.sqlite','bytes'=>filesize($db),'sha256'=>hash_file('sha256',$db)],
        ['path'=>'media/tenant-a/photo.jpg','bytes'=>filesize($img),'sha256'=>hash_file('sha256',$img)]
    ]];
    $manifest=$root.'/archive-manifest.json';
    $write=static function(array $data)use($manifest):void{
        file_put_contents($manifest,json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    };
    $write($data);
    $result=Audit::inspect($root);
    if(!$result['ok'] || $result['files']!==2 ||
       $result['external_signature_verified']!==false)
        throw new RuntimeException('RESTORE_INVENTORY_POSITIVE_FAILED');
    file_put_contents($img,'tampered');
    if(Audit::inspect($root)['ok'])throw new RuntimeException('TAMPER_ACCEPTED');
    file_put_contents($img,'synthetic media bytes; not a customer image');
    file_put_contents($root.'/untracked.bin','extra bytes');
    if(Audit::inspect($root)['ok'])throw new RuntimeException('EXTRA_FILE_ACCEPTED');
    unlink($root.'/untracked.bin');
    $data['files'][1]['path']='../../etc/shadow';$write($data);
    if(Audit::inspect($root)['ok'])throw new RuntimeException('TRAVERSAL_ACCEPTED');
    $data['files'][1]['path']='media/tenant-a/photo.jpg';$write($data);
    unlink($img);
    symlink('/etc/passwd',$img);
    if(Audit::inspect($root)['ok'])throw new RuntimeException('SYMLINK_ACCEPTED');
    unlink($img);
    file_put_contents($img,'synthetic media bytes; not a customer image');
    $data['files'][]=$data['files'][0];$write($data);
    if(Audit::inspect($root)['ok'])throw new RuntimeException('DUPLICATE_ACCEPTED');
    echo "SITE_RESTORE_ARCHIVE_INTEGRITY=PASS synthetic_db_media,checksum,tamper,extra,traversal,symlink,duplicate,no_external_signature\n";
}finally{
    foreach([$root.'/media/tenant-a/photo.jpg',$root.'/synthetic.sqlite',
             $root.'/archive-manifest.json'] as $p)@unlink($p);
    @rmdir($root.'/media/tenant-a');
    @rmdir($root.'/media');
    @rmdir($root);
}
