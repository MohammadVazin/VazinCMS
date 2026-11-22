<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Candidate-only, CLI-only control plane for one SHA-pinned package tree
 * and a separately pinned PHP entrypoint.
 *
 * This class never installs, enables or dispatches a plugin in the CMS FPM
 * process. Requests need a pre-existing explicit site manager binding,
 * an independent external Ed25519 operator receipt, and a separate durable
 * request ledger with one-time claims BEFORE any sandbox execution.
 *
 * No receipt, trust root or ledger exists in Production. In particular, an
 * inert cms_site_extension_preferences row is NOT authorization to execute.
 * Do not connect to ExtensionRuntime, App, cron or HTTP until an independent
 * product/security review has approved the whole trust chain.
 */
final class SiteScopedPluginControlPlane
{
    private const TRUST_ROOT='/etc/vazincms-extension-control';
    private const FIELD_NAMES=[
        'actor_id','bundle_key','entrypoint','expires_at','image_digest',
        'issued_at','package_sha256','request_id','sha256','site_id',
    ];

    private static function cliOnly():void
    {
        if(PHP_SAPI!=='cli'){
            throw new RuntimeException('plugin_control_cli_only');
        }
    }

    /**
     * Durable, separate filesystem-backed ledger; no in-memory queue and
     * never the CMS database, including when opened via a SECOND PDO.
     * The directory and SQLite file must be private. This does not
     * create any Production control-plane path or user account.
     */
    private static function assertSeparateLedger(PDO $siteDb,PDO $controlDb):void
    {
        if($controlDb===$siteDb ||
           (string)$controlDb->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'){
            throw new RuntimeException('plugin_control_ledger_driver_invalid');
        }
        try{
            $files=$controlDb->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
            $main=null;
            foreach($files as $row){
                if(($row['name']??'')==='main'){
                    $main=(string)($row['file']??'');
                    break;
                }
            }
            $path=is_string($main) && $main!=='' ? realpath($main) : false;
            $uid=function_exists('posix_geteuid')?posix_geteuid():-1;
            if(!is_string($path) || !is_file($path) || is_link($main) ||
               $uid<0 || fileowner($path)!==$uid ||
               fileowner(dirname($path))!==$uid ||
               (fileperms($path)&0077)!==0 ||
               (fileperms(dirname($path))&0077)!==0){
                throw new RuntimeException('plugin_control_ledger_not_private_or_durable');
            }
            $parent=realpath(dirname($path));
            if(!is_string($parent) ||
               str_starts_with($path,'/var/lib/vazincms/') ||
               str_starts_with($path,'/var/www/vazin-reserved/')){
                throw new RuntimeException('plugin_control_ledger_live_path_denied');
            }
            if((string)$siteDb->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
                $siteFiles=$siteDb->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
                foreach($siteFiles as $row){
                    if(($row['name']??'')==='main' &&
                       ($same=realpath((string)($row['file']??'')))!==false &&
                       $same===$path){
                        throw new RuntimeException('plugin_control_ledger_aliases_cms_db');
                    }
                }
            }
        }catch(RuntimeException $e){
            throw $e;
        }catch(Throwable){
            throw new RuntimeException('plugin_control_ledger_not_private_or_durable');
        }
    }

    /**
     * Initialize only the independent worker ledger. This method cannot
     * accept the CMS database and refuses an in-memory, aliased, shared or
     * production-path SQLite file. It never creates a CMS table.
     */
    public static function initializeLedger(PDO $siteDb,PDO $controlDb):void
    {
        self::cliOnly();
        self::assertSeparateLedger($siteDb,$controlDb);
        $controlDb->exec(
            "CREATE TABLE IF NOT EXISTS plugin_control_requests (".
            "request_id TEXT PRIMARY KEY,site_id INTEGER NOT NULL,actor_id INTEGER NOT NULL,".
            "request_digest TEXT NOT NULL,request_json TEXT NOT NULL,staging_root TEXT NOT NULL,".
            "state TEXT NOT NULL CHECK(state IN('pending_external_approval','claimed','succeeded','failed','review_required')),".
            "created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,worker_id TEXT NOT NULL DEFAULT '',".
            "exit_code INTEGER,timeout INTEGER,cleanup_verified INTEGER,error_code TEXT NOT NULL DEFAULT '')"
        );
        $columns=[];$requestIdPrimary=false;
        foreach($controlDb->query('PRAGMA table_info(plugin_control_requests)')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $name=(string)$row['name'];
            $columns[$name]=true;
            if($name==='request_id' && (int)$row['pk']===1)$requestIdPrimary=true;
        }
        foreach(['request_id','site_id','actor_id','request_digest','request_json',
            'staging_root','state','created_at','updated_at','worker_id','exit_code',
            'timeout','cleanup_verified','error_code'] as $required){
            if(!isset($columns[$required])){
                throw new RuntimeException('plugin_control_ledger_schema_invalid');
            }
        }
        $definition=(string)$controlDb->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='plugin_control_requests'"
        )->fetchColumn();
        if(!$requestIdPrimary ||
           !str_contains(strtolower($definition),"check(state in('pending_external_approval'") ||
           !str_contains(strtolower($definition),'primary key')){
            throw new RuntimeException('plugin_control_ledger_constraints_invalid');
        }
        $controlDb->exec(
            'CREATE INDEX IF NOT EXISTS plugin_control_pending '.
            'ON plugin_control_requests(state,created_at,request_id)'
        );
    }

    /** @return array<string,int|string> */
    public static function canonicalRequest(array $raw):array
    {
        self::cliOnly();
        $keys=array_keys($raw);
        sort($keys,SORT_STRING);
        if($keys!==self::FIELD_NAMES){
            throw new RuntimeException('plugin_control_request_fields_invalid');
        }
        $site=$raw['site_id'];
        $actor=$raw['actor_id'];
        foreach([$site,$actor] as $id){
            if(!is_int($id)||$id<1)throw new RuntimeException('plugin_control_identity_invalid');
        }
        foreach(['request_id','bundle_key','entrypoint','sha256','package_sha256','image_digest'] as $key){
            if(!is_string($raw[$key]))throw new RuntimeException('plugin_control_request_invalid');
        }
        if(preg_match('/\A[a-f0-9]{32}\z/D',$raw['request_id'])!==1 ||
           preg_match('/\A[a-z0-9][a-z0-9-]{1,78}\z/D',$raw['bundle_key'])!==1 ||
           preg_match('/\A[a-z0-9][a-z0-9-]{1,78}\.php\z/D',$raw['entrypoint'])!==1 ||
           preg_match('/\A[a-f0-9]{64}\z/D',$raw['sha256'])!==1 ||
           preg_match('/\A[a-f0-9]{64}\z/D',$raw['package_sha256'])!==1 ||
           preg_match('/\Asha256:[a-f0-9]{64}\z/D',$raw['image_digest'])!==1){
            throw new RuntimeException('plugin_control_request_invalid');
        }
        $now=time();
        if(!is_int($raw['issued_at']) || !is_int($raw['expires_at']) ||
           $raw['issued_at']>$now+30 || $raw['issued_at']<$now-900 ||
           $raw['expires_at']<$now || $raw['expires_at']>$raw['issued_at']+900 ||
           $raw['expires_at']<=$raw['issued_at']){
            throw new RuntimeException('plugin_control_grant_expired_or_invalid');
        }
        return $raw;
    }

    /**
     * Read-only authorization check (caller must provide a review copy or
     * read-only connection); no global role and no cross-site inference.
     * @return array{eligible_for_review:bool,external_approval_verified:bool,approved_to_execute:bool}
     */
    public static function assess(PDO $siteDb,array $raw,string $stagingRoot):array
    {
        $job=self::canonicalRequest($raw);
        $driver=(string)$siteDb->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['sqlite','pgsql'],true)){
            throw new RuntimeException('plugin_control_driver_denied');
        }
        try {
            $readonly=$driver==='sqlite'
                ? (string)$siteDb->query('PRAGMA query_only')->fetchColumn()==='1'
                : (string)$siteDb->query('SHOW transaction_read_only')->fetchColumn()==='on';
        } catch(Throwable) {
            $readonly=false;
        }
        if(!$readonly)throw new RuntimeException('plugin_control_site_db_not_readonly');
        $root=realpath($stagingRoot);
        $file=$root===false?false:realpath($root.'/'.$job['entrypoint']);
        if(!is_string($root) || !is_dir($root) || is_link($stagingRoot) ||
           !is_string($file) || !is_file($file) ||
           !hash_equals($root.'/'.$job['entrypoint'],$file) ||
           is_link($root.'/'.$job['entrypoint']) ||
           !hash_equals($job['sha256'],(string)hash_file('sha256',$file)) ||
           !hash_equals($job['package_sha256'],SiteScopedExtensionSandbox::packageDigest($root))){
            throw new RuntimeException('plugin_control_bundle_untrusted');
        }
        try{
            $query=$siteDb->prepare(
                'SELECT 1 FROM cms_sites s ' .
                'JOIN cms_site_memberships m ON m.site_id=s.id ' .
                'JOIN users u ON u.id=m.user_id ' .
                'WHERE s.id=:site AND m.user_id=:actor ' .
                "AND s.status IN('active','staging') " .
                "AND m.site_role IN('owner','admin') " .
                "AND u.status='active' LIMIT 1"
            );
            $query->execute([
                'site'=>$job['site_id'],'actor'=>$job['actor_id'],
            ]);
            try{$ok=$query->fetchColumn()!==false;}
            finally{$query->closeCursor();}
        }catch(Throwable){
            throw new RuntimeException('plugin_control_site_or_membership_unavailable');
        }
        if(!$ok)throw new RuntimeException('plugin_control_site_actor_denied');
        try{
            // Preferences are INERT and never activate the plugin; they are
            // one more prerequisite, not a source of trusted approval.
            // A runtime package must be individually marked trusted and
            // not active in the legacy in-process extension registry.
            $package=$siteDb->prepare(
                'SELECT e.status,e.source,x.signature_status,x.license_status ' .
                'FROM cms_extensions e ' .
                'JOIN cms_site_extension_preferences p ' .
                'ON p.extension_key=e.extension_key AND p.site_id=:site ' .
                'AND p.changed_by=:actor AND p.desired_enabled=1 ' .
                'JOIN marketplace_provenance x ' .
                'ON x.extension_key=e.extension_key AND x.version=e.version ' .
                'WHERE e.extension_key=:key AND e.extension_type=\'module\' ' .
                "AND e.source='runtime' AND e.status='inactive' " .
                'ORDER BY x.id DESC LIMIT 1'
            );
            $package->execute([
                'site'=>$job['site_id'],'actor'=>$job['actor_id'],
                'key'=>$job['bundle_key'],
            ]);
            try{$meta=$package->fetch(PDO::FETCH_ASSOC);}
            finally{$package->closeCursor();}
        }catch(Throwable){
            throw new RuntimeException('plugin_control_package_metadata_unavailable');
        }
        if(!is_array($meta) ||
           $meta['signature_status']!=='trusted' ||
           !in_array($meta['license_status'],['active','not_required'],true)){
            throw new RuntimeException('plugin_control_package_not_approved');
        }
        // A valid owner and matching source hash are necessary, not
        // sufficient. External approval cannot be inferred from CMS rows.
        return [
            'eligible_for_review'=>true,
            'external_approval_verified'=>false,
            'approved_to_execute'=>false,
        ];
    }

    /**
     * Enqueue in a separate private file-backed SQLite ledger. The schema
     * is initialized there only; CMS DB remains read-only and is never used
     * as a queue. Duplicate request IDs are idempotent only for an identical
     * canonical payload and staging root.
     * @return array{request_id:string,state:string,idempotent:bool}
     */
    public static function submit(
        PDO $siteDb,PDO $controlDb,array $raw,string $stagingRoot
    ):array {
        self::assess($siteDb,$raw,$stagingRoot);
        $job=self::canonicalRequest($raw);
        ksort($job,SORT_STRING);
        $json=json_encode($job,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $digest=hash('sha256',$json);
        $root=realpath($stagingRoot);
        if(!is_string($root))throw new RuntimeException('plugin_control_staging_root_invalid');
        self::initializeLedger($siteDb,$controlDb);
        try{
            $controlDb->exec('BEGIN IMMEDIATE');
            $check=$controlDb->prepare(
                'SELECT request_digest,request_json,staging_root,state '.
                'FROM plugin_control_requests WHERE request_id=:id'
            );
            $check->execute(['id'=>$job['request_id']]);
            try{$existing=$check->fetch(PDO::FETCH_ASSOC);}
            finally{$check->closeCursor();}
            if(is_array($existing)){
                if(!hash_equals((string)$existing['request_digest'],$digest) ||
                   !hash_equals((string)$existing['request_json'],$json) ||
                   !hash_equals((string)$existing['staging_root'],$root)){
                    throw new RuntimeException('plugin_control_request_id_conflict');
                }
                $state=(string)$existing['state'];
                $controlDb->exec('COMMIT');
                return ['request_id'=>$job['request_id'],'state'=>$state,'idempotent'=>true];
            }
            $now=time();
            $insert=$controlDb->prepare(
                'INSERT INTO plugin_control_requests('.
                'request_id,site_id,actor_id,request_digest,request_json,staging_root,state,created_at,updated_at'.
                ") VALUES(:id,:site,:actor,:digest,:json,:root,'pending_external_approval',:created,:updated)"
            );
            $insert->execute([
                'id'=>$job['request_id'],'site'=>$job['site_id'],
                'actor'=>$job['actor_id'],'digest'=>$digest,'json'=>$json,
                'root'=>$root,'created'=>$now,'updated'=>$now,
            ]);
            $controlDb->exec('COMMIT');
            return [
                'request_id'=>$job['request_id'],
                'state'=>'pending_external_approval',
                'idempotent'=>false,
            ];
        }catch(Throwable $error){
            try{$controlDb->exec('ROLLBACK');}catch(Throwable){}
            throw $error;
        }
    }

    /**
     * Detached-signer gate. The trusted key and receipt MUST be installed
     * independently outside the candidate by a separate authorized owner.
     * The signer is NEVER created or called here.
     */
    private static function externalApproval(
        array $job,string $stagingRoot
    ):bool {
        if(!extension_loaded('sodium'))return false;
        $keyPath=self::TRUST_ROOT.'/operator-ed25519-public.hex';
        $signaturePath=self::TRUST_ROOT.'/receipts/'.
            $job['request_id'].'.sig.hex';
        foreach(['/etc',self::TRUST_ROOT,self::TRUST_ROOT.'/receipts'] as $folder){
            if(!is_dir($folder) || is_link($folder) ||
               fileowner($folder)!==0 || (fileperms($folder)&0022)!==0){
                return false;
            }
        }
        foreach([$keyPath,$signaturePath] as $file){
            $size=@filesize($file);
            if(!is_file($file) || is_link($file) ||
               !is_int($size) || $size<1 || $size>256 ||
               fileowner($file)!==0 || (fileperms($file)&0022)!==0){
                return false;
            }
        }
        $key=strtolower(trim((string)file_get_contents($keyPath)));
        $signature=strtolower(trim((string)file_get_contents($signaturePath)));
        if(preg_match('/\A[a-f0-9]{64}\z/D',$key)!==1 ||
           preg_match('/\A[a-f0-9]{128}\z/D',$signature)!==1)return false;
        $root=realpath($stagingRoot);
        if(!is_string($root))return false;
        $msg="VAZINCMS-PLUGIN-EXEC-V1\n".
            "request_id=".$job['request_id']."\n".
            "site_id=".$job['site_id']."\n".
            "actor_id=".$job['actor_id']."\n".
            "bundle_key=".$job['bundle_key']."\n".
            "entrypoint=".$job['entrypoint']."\n".
            "issued_at=".$job['issued_at']."\n".
            "expires_at=".$job['expires_at']."\n".
            "sha256=".$job['sha256']."\n".
            "package_sha256=".$job['package_sha256']."\n".
            "image_digest=".$job['image_digest']."\n".
            "staging_root=".$root."\n";
        try{
            return sodium_crypto_sign_verify_detached(
                sodium_hex2bin($signature),$msg,sodium_hex2bin($key)
            );
        }catch(Throwable){return false;}
    }

    /**
     * Needs a preexisting submitted request, an independent signer and a
     * one-time claim. Never store plugin stdout or errors in the ledger.
     * @return array{exit:int,timeout:bool,cleanup_verified:?bool,approved_execution:bool}
     */
    public static function executeApproved(
        PDO $siteDb,PDO $controlDb,array $raw,string $stagingRoot,
        int $timeoutSeconds=7,?string $expectedImageDigest=null,
        ?string $dockerSocket=null,?string $workerHome=null
    ):array {
        self::assess($siteDb,$raw,$stagingRoot);
        self::initializeLedger($siteDb,$controlDb);
        $job=self::canonicalRequest($raw);
        ksort($job,SORT_STRING);
        if($timeoutSeconds<1 || $timeoutSeconds>30){
            throw new RuntimeException('plugin_control_timeout_invalid');
        }
        $json=json_encode($job,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $digest=hash('sha256',$json);
        $root=realpath($stagingRoot);
        if(!is_string($root))throw new RuntimeException('plugin_control_staging_root_invalid');
        $lookup=$controlDb->prepare(
            'SELECT request_digest,request_json,staging_root,state '.
            'FROM plugin_control_requests WHERE request_id=:id'
        );
        $lookup->execute(['id'=>$job['request_id']]);
        try{$queued=$lookup->fetch(PDO::FETCH_ASSOC);}
        finally{$lookup->closeCursor();}
        if(!is_array($queued) ||
           !hash_equals((string)$queued['request_digest'],$digest) ||
           !hash_equals((string)$queued['request_json'],$json) ||
           !hash_equals((string)$queued['staging_root'],$root) ||
           $queued['state']!=='pending_external_approval'){
            throw new RuntimeException('plugin_control_queued_request_mismatch');
        }
        // No CMS preference, local metadata or caller flag can substitute
        // for the independently installed operator receipt.
        if(!self::externalApproval($job,$root)){
            throw new RuntimeException('plugin_control_external_approval_missing');
        }
        if(!is_string($expectedImageDigest) ||
           !hash_equals($expectedImageDigest,$job['image_digest'])){
            throw new RuntimeException('plugin_control_worker_image_pin_mismatch');
        }
        if(!is_string($dockerSocket) || !is_string($workerHome)){
            throw new RuntimeException('plugin_control_rootless_worker_unavailable');
        }
        SiteScopedExtensionSandbox::assertRootlessDaemon(
            $dockerSocket,$workerHome,$job['image_digest']
        );
        $workerId='uid-'.posix_geteuid().'-pid-'.getmypid();
        // Fail closed if the one-time claim cannot be durably stored BEFORE
        // the worker is allowed to invoke Docker.
        try{
            $controlDb->exec('BEGIN IMMEDIATE');
            $update=$controlDb->prepare(
                'UPDATE plugin_control_requests SET state=\'claimed\',worker_id=:worker,updated_at=:now '.
                'WHERE request_id=:id AND site_id=:site AND actor_id=:actor '.
                'AND request_digest=:digest AND state=\'pending_external_approval\''
            );
            $now=time();
            $update->execute([
                'worker'=>$workerId,'now'=>$now,'id'=>$job['request_id'],
                'site'=>$job['site_id'],'actor'=>$job['actor_id'],'digest'=>$digest,
            ]);
            if($update->rowCount()!==1){
                throw new RuntimeException('plugin_control_claim_denied');
            }
            $controlDb->exec('COMMIT');
        }catch(Throwable $error){
            try{$controlDb->exec('ROLLBACK');}catch(Throwable){}
            throw $error;
        }
        $state='review_required';$exit=125;$timedOut=false;$clean=false;
        try{
            $result=SiteScopedExtensionSandbox::executeRootless(
                $job['site_id'],$job['bundle_key'],$root,$root.'/'.$job['entrypoint'],
                $job['sha256'],$job['package_sha256'],$job['image_digest'],
                $dockerSocket,$workerHome,$timeoutSeconds
            );
            $exit=(int)$result['exit'];
            $timedOut=(bool)$result['timeout'];
            $clean=$result['cleanup_verified']===true;
            if($exit===0 && !$timedOut && $clean)$state='succeeded';
            elseif($clean && !$timedOut)$state='failed';
        }finally{
            // No stdout/stderr, secrets or plugin-supplied error text enters
            // the durable ledger. Ambiguous state is never auto-retried.
            $update=$controlDb->prepare(
                'UPDATE plugin_control_requests SET state=:state,exit_code=:exit,'.
                'timeout=:timeout,cleanup_verified=:clean,updated_at=:now '.
                'WHERE request_id=:id AND state=\'claimed\''
            );
            $update->execute([
                'state'=>$state,'exit'=>$exit,'timeout'=>$timedOut?1:0,
                'clean'=>$clean?1:0,'now'=>time(),'id'=>$job['request_id']
            ]);
            if($update->rowCount()!==1){
                throw new RuntimeException('plugin_control_finalization_uncertain');
            }
        }
        return [
            'exit'=>$exit,'timeout'=>$timedOut,
            'cleanup_verified'=>$clean,
            'approved_execution'=>true,
        ];
    }
}
