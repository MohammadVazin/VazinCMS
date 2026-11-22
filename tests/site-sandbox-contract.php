<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/src/SiteScopedExtensionSandbox.php';
use VazinCMS\SiteScopedExtensionSandbox as Sandbox;
$assert=static function(bool $v,string $why):void {
    if(!$v)throw new RuntimeException('SANDBOX_CONTRACT_FAIL: '.$why);
};
$denied=static function(callable $fn,string $code)use($assert):void{
    try{$fn();}catch(RuntimeException $e){
        $assert($e->getMessage()===$code,'wrong reason '.$e->getMessage());
        return;
    }
    throw new RuntimeException('SANDBOX_UNEXPECTED_SUCCESS '.$code);
};
$dir=sys_get_temp_dir().'/vazincms-phase14-sandbox-fixture-'.bin2hex(random_bytes(5));
if(!mkdir($dir,0700,true))throw new RuntimeException('fixture_dir');
try{
    mkdir($dir.'/core',0700);
    file_put_contents($dir.'/core/Core.php',"<?php class SandboxCoreFixture {}\n");
    $entry=$dir.'/sample-qa.php';
    file_put_contents($entry,"<?php require __DIR__.'/core/Core.php'; echo 'synthetic';\n");
    $digest=hash_file('sha256',$entry);
    $packageDigest=Sandbox::packageDigest($dir);
    $image='sha256:'.str_repeat('a',64);
    $cmd=Sandbox::command(1,'sample-qa',$dir,'sample-qa.php',$digest,$packageDigest,$image);
    $names=array_values(array_filter($cmd,static fn(string $arg):bool=>
        str_starts_with($arg,'--name=vazincms-sbox-')
    ));
    $assert(count($names)===1 &&
        preg_match('/^--name=vazincms-sbox-[a-f0-9]{24}$/D',$names[0])===1,
        'sandbox has no isolated random container identity');
    $source=(string)file_get_contents($root.'/src/SiteScopedExtensionSandbox.php');
    $assert(str_contains($source,'forceRemoveAndVerify($containerName,$environment)') &&
        str_contains($source,"'cleanup_verified'"),
        'timed-out container cleanup verification not wired');
    $arguments=implode("\0",$cmd);
    foreach(['--network=none','--read-only','--cap-drop=ALL',
        '--security-opt=no-new-privileges','--pids-limit=32',
        '--user=65534:65534','--memory=128m','--cpus=0.5',
        '--pull=never','open_basedir=/sandbox/package:/tmp'] as $flag){
        $assert(str_contains($arguments,$flag),'missing hardening flag '.$flag);
    }
    $assert($cmd[0]==='docker'&&$cmd[1]==='run',
        'sandbox argv not Docker array');
    $mounts=array_values(array_filter($cmd,static fn($item)=>str_starts_with(
        $item,'--mount=')));
    $assert(count($mounts)===1 && str_contains($mounts[0],
        'source='.$dir.',target=/sandbox/package,readonly'),
        'package directory is not mounted exactly once');
    $assert(in_array('/sandbox/package/sample-qa.php',$cmd,true),
        'entrypoint does not resolve inside mounted package');
    $assert(!str_contains($arguments,'/var/lib/vazincms'),'prod folder visible');
    $assert(!str_contains($arguments,'DB_PASSWORD'),'DB env in sandbox');

    $denied(static fn()=>Sandbox::command(0,'sample-qa',$dir,$entry,$digest,$packageDigest,$image),
        'tenant_sandbox_input_invalid');
    $denied(static fn()=>Sandbox::command(1,'../unsafe',$dir,$entry,$digest,$packageDigest,$image),
        'tenant_sandbox_input_invalid');
    $denied(static fn()=>Sandbox::command(1,'sample-qa',$dir,'sample-qa.php',
        str_repeat('f',64),$packageDigest,$image),'tenant_sandbox_untrusted_bundle');
    $denied(static fn()=>Sandbox::command(1,'sample-qa',$dir,'sample-qa.php',$digest,
        str_repeat('f',64),$image),'tenant_sandbox_untrusted_bundle');
    $denied(static fn()=>Sandbox::command(1,'sample-qa',$dir,'sample-qa.php',$digest,$packageDigest,
        'latest'),'tenant_sandbox_input_invalid');
    symlink($entry,$dir.'/linked.php');
    $denied(static fn()=>Sandbox::packageDigest($dir),
        'tenant_sandbox_bundle_symlink_denied');
    unlink($dir.'/linked.php');
    $denied(static fn()=>Sandbox::executeRootless(1,'sample-qa',$dir,$entry,
        $digest,$packageDigest,$image,'/var/run/docker.sock','/root',0),
        'tenant_sandbox_execution_denied');
    echo "SITE_SANDBOX_COMMAND_CONTRACT=PASS full_package_mount,tree_digest,nested_include,nonroot,no_network,read_only,no_secrets,invalid_inputs_denied\n";
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
