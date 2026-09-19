<?php
declare(strict_types=1);

$root=realpath(dirname(__DIR__));$version=trim((string)file_get_contents($root.'/VERSION'));
$output=$argv[1]??dirname($root).'/VazinCMS-'.$version.'.zip';
$outputParent=realpath(dirname($output));
if(!class_exists(ZipArchive::class))throw new RuntimeException('PHP Zip extension is required.');
if($root===false||$outputParent===false||$outputParent===$root||str_starts_with($outputParent,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Release archive must be outside source root.');
if(file_exists($output))throw new RuntimeException('Release archive already exists: '.$output);
$base='VazinCMS-'.$version;$directories=[];$files=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
foreach($iterator as$entry){
    if($entry->isLink())throw new RuntimeException('Symlink is not allowed in release: '.$entry->getPathname());
    $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($entry->getPathname(),strlen($root)+1));
    $segments=explode('/',$relative);
    if(
        $relative==='.env' ||
        (str_starts_with($relative,'.env.') && $relative!=='.env.example') ||
        $relative==='storage' ||
        str_starts_with($relative,'storage/') ||
        $relative==='public/uploads' ||
        str_starts_with($relative,'public/uploads/') ||
        in_array('__pycache__',$segments,true) ||
        str_ends_with($relative,'.pyc')
    )continue;
    if($entry->isDir())$directories[]=$relative;elseif($entry->isFile())$files[]=$relative;
}
sort($directories,SORT_STRING);sort($files,SORT_STRING);
$manifestFile=$root.DIRECTORY_SEPARATOR.'MANIFEST.sha256';$manifest=file($manifestFile,FILE_IGNORE_NEW_LINES);
if($manifest===false)throw new RuntimeException('Cannot read MANIFEST.sha256.');
$manifestFiles=[];
foreach($manifest as$line){
    if(preg_match('/^([a-f0-9]{64})  ([^\x00-\x1f\x7f]+)$/D',$line,$match)!==1)throw new RuntimeException('MANIFEST.sha256 is not canonical.');
    [$unused,$expected,$relative]=$match;
    if(isset($manifestFiles[$relative])||str_contains($relative,'\\')||str_starts_with($relative,'/')||in_array('..',explode('/',$relative),true))throw new RuntimeException('MANIFEST.sha256 contains an unsafe or duplicate path.');
    $source=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    if(!is_file($source)||!hash_equals($expected,(string)hash_file('sha256',$source)))throw new RuntimeException('Manifest mismatch: '.$relative);
    $manifestFiles[$relative]=true;
}
$packagedFiles=array_fill_keys(array_values(array_filter($files,static fn(string$relative):bool=>$relative!=='MANIFEST.sha256')),true);
if(array_keys($manifestFiles)!==array_keys($packagedFiles))throw new RuntimeException('MANIFEST.sha256 does not exactly cover the release files.');
$zip=new ZipArchive();
if($zip->open($output,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create release archive.');
try{
    $zip->addEmptyDir($base);$zip->setExternalAttributesName($base.'/',ZipArchive::OPSYS_UNIX,040755<<16);
    foreach($directories as$relative){$name=$base.'/'.$relative.'/';$zip->addEmptyDir($name);$zip->setExternalAttributesName($name,ZipArchive::OPSYS_UNIX,040755<<16);}
    foreach($files as$relative){$source=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);$name=$base.'/'.$relative;if(!$zip->addFile($source,$name))throw new RuntimeException('Cannot add '.$relative);$executable=str_ends_with($relative,'.sh')||$relative==='deploy/atomic_release.py';$zip->setExternalAttributesName($name,ZipArchive::OPSYS_UNIX,($executable?0100755:0100644)<<16);}
}catch(Throwable$error){$zip->close();@unlink($output);throw$error;}
if(!$zip->close()){@unlink($output);throw new RuntimeException('Cannot finalize release archive.');}
$hash=hash_file('sha256',$output);$checksum=$output.'.sha256';
if(file_put_contents($checksum,$hash.'  '.basename($output).PHP_EOL,LOCK_EX)===false)throw new RuntimeException('Cannot write archive checksum.');
echo basename($output).' '.$hash.PHP_EOL;
