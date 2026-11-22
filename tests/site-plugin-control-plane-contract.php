<?php
declare(strict_types=1);
/**
 * Candidate-only control-plane QA. Tenant and ledger data are synthetic.
 * Package bytes are tree-hashed; the worker must not execute or contact
 * Docker until an independently installed Ed25519 approval is verified.
 */
require dirname(__DIR__).'/src/SiteScopedPluginControlPlane.php';
require dirname(__DIR__).'/src/SiteScopedExtensionSandbox.php';
require dirname(__DIR__).'/src/SiteScopedPluginWorker.php';
require dirname(__DIR__).'/src/SiteScopedPluginCompatibility.php';
use VazinCMS\SiteScopedPluginControlPlane as CP;
use VazinCMS\SiteScopedExtensionSandbox as Sandbox;
use VazinCMS\SiteScopedPluginWorker as Worker;

$need=static function(bool $condition,string $why):void{
    if(!$condition)throw new RuntimeException('PLUGIN_CONTROL_QA_FAIL: '.$why);
};
$denied=static function(callable $callback,string $code) use($need):void{
    try{$callback();}
    catch(RuntimeException $e){
        $need($e->getMessage()===$code,'wrong_denial_'.$e->getMessage());
        return;
    }
    throw new RuntimeException('PLUGIN_CONTROL_QA_FAIL: denied_path_accepted '.$code);
};

$site=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$site->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,status TEXT)');
$site->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT)');
$site->exec('CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT)');
$site->exec('CREATE TABLE cms_extensions(extension_key TEXT PRIMARY KEY,extension_type TEXT,version TEXT,source TEXT,status TEXT)');
$site->exec('CREATE TABLE cms_site_extension_preferences(site_id INTEGER,extension_key TEXT,changed_by INTEGER,desired_enabled INTEGER)');
$site->exec('CREATE TABLE marketplace_provenance(id INTEGER PRIMARY KEY,extension_key TEXT,version TEXT,signature_status TEXT,license_status TEXT)');
$site->exec("INSERT INTO cms_sites VALUES(1,'active'),(2,'active')");
$site->exec("INSERT INTO users VALUES(10,'active'),(20,'active'),(30,'disabled')");
$site->exec("INSERT INTO cms_site_memberships VALUES(1,10,'owner'),(2,20,'admin'),(1,30,'owner')");
$site->exec("INSERT INTO cms_extensions VALUES('sample-qa','module','1.0','runtime','inactive')");
$site->exec("INSERT INTO cms_site_extension_preferences VALUES(1,'sample-qa',10,1)");
$site->exec("INSERT INTO marketplace_provenance VALUES(1,'sample-qa','1.0','trusted','not_required')");
$site->exec("INSERT INTO cms_extensions VALUES('revoked-qa','module','1.0','runtime','inactive')");
$site->exec("INSERT INTO cms_site_extension_preferences VALUES(1,'revoked-qa',10,1)");
$site->exec("INSERT INTO marketplace_provenance VALUES(2,'revoked-qa','1.0','revoked','not_required')");
$site->exec("INSERT INTO cms_extensions VALUES('first-party-qa','module','1.0','bundled','active')");
$site->exec("INSERT INTO cms_site_extension_preferences VALUES(1,'first-party-qa',10,1)");
$site->exec("INSERT INTO marketplace_provenance VALUES(3,'first-party-qa','1.0','trusted','not_required')");
$site->exec('PRAGMA query_only=ON');
$need((int)$site->query('PRAGMA query_only')->fetchColumn()===1,'source_not_readonly');

$fixture=sys_get_temp_dir().'/vazincms-plugin-cp-'.bin2hex(random_bytes(8));
$need(mkdir($fixture,0700,true),'test_fixture_dir');
$bundleRoot=$fixture.'/bundle';
$revokedRoot=$fixture.'/revoked-bundle';
$firstPartyRoot=$fixture.'/first-party-bundle';
$need(mkdir($bundleRoot,0700),'test_bundle_dir');
$need(mkdir($revokedRoot,0700),'revoked_bundle_dir');
$need(mkdir($firstPartyRoot,0700),'first_party_bundle_dir');
$ledgerFile=$fixture.'/control-ledger.sqlite';
$ledger=new PDO('sqlite:'.$ledgerFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
chmod($ledgerFile,0600);
CP::initializeLedger($site,$ledger);
$entry=$bundleRoot.'/sample-qa.php';
$marker=$fixture.'/plugin-escaped.txt';
$revoked=$revokedRoot.'/revoked-qa.php';
try {
    file_put_contents($entry,"<?php echo 'synthetic';\n");
    $now=time();
    $request=[
        'request_id'=>bin2hex(random_bytes(16)),
        'site_id'=>1,'actor_id'=>10,'bundle_key'=>'sample-qa',
        'entrypoint'=>'sample-qa.php',
        'sha256'=>hash_file('sha256',$entry),
        'package_sha256'=>Sandbox::packageDigest($bundleRoot),
        'image_digest'=>'sha256:'.str_repeat('a',64),
        'issued_at'=>$now,'expires_at'=>$now+300,
    ];
    file_put_contents($revoked,"<?php echo 'revoked';\n");
    $revokedRequest=$request;
    $revokedRequest['bundle_key']='revoked-qa';
    $revokedRequest['entrypoint']='revoked-qa.php';
    $revokedRequest['sha256']=hash_file('sha256',$revoked);
    $revokedRequest['package_sha256']=Sandbox::packageDigest($revokedRoot);
    $denied(static fn()=>CP::assess($site,$revokedRequest,$revokedRoot),
        'plugin_control_package_not_approved');
    $firstPartyEntry=$firstPartyRoot.'/first-party-qa.php';
    file_put_contents($firstPartyEntry,"<?php echo 'bundled';\\n");
    $firstPartyRequest=$request;
    $firstPartyRequest['bundle_key']='first-party-qa';
    $firstPartyRequest['entrypoint']='first-party-qa.php';
    $firstPartyRequest['sha256']=hash_file('sha256',$firstPartyEntry);
    $firstPartyRequest['package_sha256']=Sandbox::packageDigest($firstPartyRoot);
    $denied(static fn()=>CP::assess($site,$firstPartyRequest,$firstPartyRoot),
        'plugin_control_package_not_approved');
    $notReadonly=new PDO('sqlite::memory:',null,null,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
    ]);
    $denied(static fn()=>CP::assess($notReadonly,$request,$bundleRoot),
        'plugin_control_site_db_not_readonly');
    $plan=CP::assess($site,$request,$bundleRoot);
    $need($plan['eligible_for_review']===true &&
        $plan['approved_to_execute']===false &&
        $plan['external_approval_verified']===false,
        'untrusted_plan_advertised_ready');
    $first=CP::submit($site,$ledger,$request,$bundleRoot);
    $need($first['state']==='pending_external_approval' &&
        $first['idempotent']===false,'first_enqueue_denied');
    $duplicate=CP::submit($site,$ledger,$request,$bundleRoot);
    $need($duplicate['idempotent']===true &&
        $duplicate['state']==='pending_external_approval','duplicate_requeued');

    // Any sibling file mutation invalidates the package tree pin.
    $sibling=$bundleRoot.'/core.php';
    file_put_contents($sibling,"<?php // changed sibling\n");
    $denied(static fn()=>CP::assess($site,$request,$bundleRoot),
        'plugin_control_bundle_untrusted');
    unlink($sibling);
    symlink($entry,$bundleRoot.'/linked.php');
    $denied(static fn()=>Sandbox::packageDigest($bundleRoot),
        'tenant_sandbox_bundle_symlink_denied');
    unlink($bundleRoot.'/linked.php');
    $need(hash_equals($request['package_sha256'],Sandbox::packageDigest($bundleRoot)),
        'package_digest_not_restored');

    // The real one-shot worker discovers the durable job but cannot claim
    // or contact Docker without an independently installed Ed25519 receipt.
    $dockerMarker=$fixture.'/docker-was-contacted';
    $fakeDocker=$fixture.'/docker';
    file_put_contents($fakeDocker,"#!/bin/sh\ntouch ".escapeshellarg($dockerMarker)."\nexit 99\n");
    chmod($fakeDocker,0700);
    $oldPath=(string)getenv('PATH');
    putenv('PATH='.$fixture.':'.$oldPath);
    try{
        $waiting=Worker::runOnce(
            $site,$ledger,$bundleRoot,$request['image_digest'],
            '/var/run/docker.sock',$fixture,7
        );
    }finally{
        putenv('PATH='.$oldPath);
    }
    $need($waiting['state']==='waiting_external_approval',
        'worker_did_not_wait_for_external_approval');
    $need(!is_file($dockerMarker),'docker_contacted_without_external_approval');
    $need((string)$ledger->query(
        'SELECT state FROM plugin_control_requests LIMIT 1')->fetchColumn()
        ==='pending_external_approval','worker_claimed_without_approval');
    $reopened=new PDO('sqlite:'.$ledgerFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $reopened->exec('PRAGMA query_only=ON');
    $need((int)$reopened->query('SELECT COUNT(*) FROM plugin_control_requests')->fetchColumn()===1,
        'queue_not_durable_across_connections');
    $reopened=null;

    $memoryQueue=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $denied(static fn()=>CP::submit($site,$memoryQueue,$request,$bundleRoot),
        'plugin_control_ledger_not_private_or_durable');
    $memoryQueue=null;
    $badLedgerFile=$fixture.'/bad-ledger.sqlite';
    $badLedger=new PDO('sqlite:'.$badLedgerFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    chmod($badLedgerFile,0600);
    $badLedger->exec(
        'CREATE TABLE plugin_control_requests('.
        'request_id TEXT,site_id INTEGER,actor_id INTEGER,request_digest TEXT,'.
        'request_json TEXT,staging_root TEXT,state TEXT,created_at INTEGER,'.
        'updated_at INTEGER,worker_id TEXT,exit_code INTEGER,timeout INTEGER,'.
        'cleanup_verified INTEGER,error_code TEXT)'
    );
    $denied(static fn()=>CP::initializeLedger($site,$badLedger),
        'plugin_control_ledger_constraints_invalid');
    $badLedger=null;

    $mutated=$request;$mutated['image_digest']='sha256:'.str_repeat('b',64);
    $denied(static fn()=>CP::submit($site,$ledger,$mutated,$bundleRoot),
        'plugin_control_request_id_conflict');
    $cross=$request;$cross['actor_id']=20;
    $denied(static fn()=>CP::assess($site,$cross,$bundleRoot),
        'plugin_control_site_actor_denied');
    $disabled=$request;$disabled['actor_id']=30;
    $denied(static fn()=>CP::assess($site,$disabled,$bundleRoot),
        'plugin_control_site_actor_denied');
    $changed=$request;$changed['sha256']=str_repeat('f',64);
    $denied(static fn()=>CP::assess($site,$changed,$bundleRoot),
        'plugin_control_bundle_untrusted');
    $expired=$request;$expired['expires_at']=$now-1;
    $denied(static fn()=>CP::assess($site,$expired,$bundleRoot),
        'plugin_control_grant_expired_or_invalid');
    $extra=$request;$extra['approved_by']='self';
    $denied(static fn()=>CP::assess($site,$extra,$bundleRoot),
        'plugin_control_request_fields_invalid');
    $inplace=$request;$inplace['entrypoint']='../sample-qa.php';
    $denied(static fn()=>CP::assess($site,$inplace,$bundleRoot),
        'plugin_control_request_invalid');
    $denied(static fn()=>CP::submit($site,$site,$request,$bundleRoot),
        'plugin_control_ledger_driver_invalid');
    $denied(static fn()=>CP::executeApproved($site,$ledger,$request,$bundleRoot),
        'plugin_control_external_approval_missing');
    $need((string)$ledger->query(
        'SELECT state FROM plugin_control_requests LIMIT 1')->fetchColumn()
        ==='pending_external_approval','missing_receipt_changed_job');
    $need((int)$ledger->query(
        'SELECT COUNT(*) FROM plugin_control_requests')->fetchColumn()===1,
        'unexpected_job_count');
    $need(!is_file($marker),'plugin_executed_with_missing_approval');
    echo "PLUGIN_CONTROL_PLANE_QA=PASS package_tree_pin,sibling_mutation_denied,symlink_denied,bundled_source_denied,durable_queue,one_shot_worker,external_approval_missing,no_docker_before_approval,NO_EXECUTION\n";
}finally {
    $ledger=null;$reopened=null;$badLedger=null;$site=null;
    gc_collect_cycles();
    $iterator=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($iterator as $item){
        if($item->isDir() && !$item->isLink())@rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($fixture);
}
