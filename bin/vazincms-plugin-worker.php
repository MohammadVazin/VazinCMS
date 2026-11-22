<?php
declare(strict_types=1);

/**
 * One-shot external worker entrypoint.
 *
 * No loop or service installation. Requires an independently provisioned,
 * root-owned worker.json, a dedicated non-root account and its rootless
 * Docker daemon. The CMS web user and rootful /var/run/docker.sock are
 * deliberately unsupported.
 */
if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"PLUGIN_WORKER=DENIED cli_only\n");exit(77);
}
umask(0077);
$configPath='/etc/vazincms-extension-control/worker.json';
if(!is_file($configPath) || is_link($configPath)){
    fwrite(STDERR,"PLUGIN_WORKER=BLOCKED worker_config_missing\n");exit(78);
}
$directory=dirname($configPath);
if(fileowner($directory)!==0 || (fileperms($directory)&0022)!==0 ||
   fileowner($configPath)!==0 || (fileperms($configPath)&0022)!==0 ||
   !is_readable($configPath) || filesize($configPath)>8192){
    fwrite(STDERR,"PLUGIN_WORKER=DENIED worker_config_permissions\n");exit(77);
}
if(!function_exists('posix_geteuid')){
    fwrite(STDERR,"PLUGIN_WORKER=BLOCKED posix_required\n");exit(78);
}
$uid=posix_geteuid();
if($uid<1){
    fwrite(STDERR,"PLUGIN_WORKER=DENIED must_not_run_as_root\n");exit(77);
}
try{
    $config=json_decode((string)file_get_contents($configPath),true,16,JSON_THROW_ON_ERROR);
}catch(Throwable){
    fwrite(STDERR,"PLUGIN_WORKER=DENIED worker_config_invalid\n");exit(65);
}
$fields=['docker_home','docker_socket','image_digest','ledger_path',
    'schema','site_db_path','staging_root','timeout_seconds','worker_uid'];
$keys=is_array($config)?array_keys($config):[];
sort($keys,SORT_STRING);sort($fields,SORT_STRING);
if($keys!==$fields || ($config['schema']??null)!==1 ||
   ($config['worker_uid']??null)!==$uid ||
   !is_int($config['timeout_seconds']) ||
   $config['timeout_seconds']<1 || $config['timeout_seconds']>30){
    fwrite(STDERR,"PLUGIN_WORKER=DENIED worker_config_schema_or_uid\n");exit(65);
}
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='VazinCMS\\';
    if(!str_starts_with($class,$prefix))return;
    $file=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file) && !is_link($file))require $file;
});
try{
    // Do not contact Docker until an independent signed approval is
    // verified inside executeApproved. A pending unsigned job must not
    // trigger even a daemon info/image inspection request.
    $sitePath=realpath((string)$config['site_db_path']);
    if(!is_string($sitePath) || !is_file($sitePath) ||
       is_link((string)$config['site_db_path']) || !is_readable($sitePath)){
        throw new RuntimeException('worker_site_db_unavailable');
    }
    $ledgerPath=realpath((string)$config['ledger_path']);
    if(!is_string($ledgerPath) || !is_file($ledgerPath) ||
       is_link((string)$config['ledger_path']) ||
       fileowner($ledgerPath)!==$uid || (fileperms($ledgerPath)&0077)!==0 ||
       fileowner(dirname($ledgerPath))!==$uid ||
       (fileperms(dirname($ledgerPath))&0077)!==0){
        throw new RuntimeException('worker_ledger_not_private');
    }
    $staging=(string)$config['staging_root'];
    $stagingReal=realpath($staging);
    if(!is_string($stagingReal) || !is_dir($stagingReal) ||
       is_link($staging) || fileowner($stagingReal)!==$uid ||
       (fileperms($stagingReal)&0077)!==0){
        throw new RuntimeException('worker_staging_not_private');
    }
    $siteDb=new PDO('sqlite:file:'.$sitePath.'?mode=ro',null,null,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    $siteDb->exec('PRAGMA query_only=ON');
    if((int)$siteDb->query('PRAGMA query_only')->fetchColumn()!==1){
        throw new RuntimeException('worker_site_db_not_readonly');
    }
    $ledger=new PDO('sqlite:'.$ledgerPath,null,null,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    $result=VazinCMS\SiteScopedPluginWorker::runOnce(
        $siteDb,$ledger,$stagingReal,(string)$config['image_digest'],
        (string)$config['docker_socket'],(string)$config['docker_home'],
        (int)$config['timeout_seconds']
    );
    echo 'PLUGIN_WORKER_STATE='.$result['state']."\n";
    if(($result['state']??'')==='blocked_missing_capabilities' &&
       is_array($result['missing_capabilities']??null)){
        echo 'PLUGIN_WORKER_MISSING_CAPABILITIES='.
            implode(',',array_map('strval',$result['missing_capabilities']))."\n";
    }
    exit(0);
}catch(Throwable $error){
    // Do not leak paths, DSNs, plugin output or exception text.
    $code=$error instanceof RuntimeException?$error->getMessage():'worker_internal_error';
    $allowed=[
        'plugin_worker_rootless_socket_required','plugin_worker_home_not_private',
        'plugin_worker_rootless_daemon_required','plugin_worker_image_digest_mismatch',
        'plugin_worker_docker_probe_unavailable','plugin_worker_docker_probe_failed',
        'plugin_worker_docker_probe_timeout','worker_site_db_unavailable',
        'worker_ledger_not_private','worker_staging_not_private',
        'worker_site_db_not_readonly','plugin_control_ledger_not_private_or_durable',
        'plugin_control_ledger_live_path_denied','plugin_control_ledger_schema_invalid',
        'plugin_control_site_or_membership_unavailable',
        'plugin_control_package_metadata_unavailable',
    ];
    fwrite(STDERR,'PLUGIN_WORKER=BLOCKED '.(in_array($code,$allowed,true)?$code:'worker_preflight_failed')."\n");
    exit(69);
}
