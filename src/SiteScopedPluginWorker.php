<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * One-shot CLI queue consumer. No loop, HTTP route, cron or CMS-FPM hook.
 *
 * It consumes only durable pending requests, re-validates their exact
 * payload/staging/image pins, and delegates the one-time claim plus external
 * Ed25519 approval check to SiteScopedPluginControlPlane. A missing approval
 * leaves the request pending and never touches Docker.
 */
final class SiteScopedPluginWorker
{
    /**
     * Process at most ONE queued request. No automatic retry or polling.
     *
     * @return array{state:string,request_id?:string,exit?:int,timeout?:bool,cleanup_verified?:bool}
     */
    public static function runOnce(
        PDO $siteDb,
        PDO $ledger,
        string $stagingRoot,
        string $expectedImageDigest,
        string $dockerSocket,
        string $workerHome,
        int $timeoutSeconds=7
    ):array {
        if(PHP_SAPI!=='cli'){
            throw new RuntimeException('plugin_worker_cli_only');
        }
        if(preg_match('/\Asha256:[a-f0-9]{64}\z/D',$expectedImageDigest)!==1){
            throw new RuntimeException('plugin_worker_image_pin_invalid');
        }
        SiteScopedPluginControlPlane::initializeLedger($siteDb,$ledger);
        $root=realpath($stagingRoot);
        if(!is_string($root) || !is_dir($root) || is_link($stagingRoot)){
            throw new RuntimeException('plugin_worker_staging_root_invalid');
        }
        $query=$ledger->query(
            "SELECT request_id,request_digest,request_json,staging_root,state ".
            "FROM plugin_control_requests WHERE state='pending_external_approval' ".
            'ORDER BY created_at,request_id LIMIT 1'
        );
        try{$row=$query->fetch(PDO::FETCH_ASSOC);}
        finally{$query->closeCursor();}
        if(!is_array($row))return ['state'=>'idle'];
        $id=(string)$row['request_id'];
        try{
            $raw=json_decode((string)$row['request_json'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($raw))throw new RuntimeException('invalid_request_json');
            $job=SiteScopedPluginControlPlane::canonicalRequest($raw);
            ksort($job,SORT_STRING);
            $json=json_encode($job,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $digest=hash('sha256',$json);
            if(!hash_equals((string)$row['request_digest'],$digest) ||
               !hash_equals((string)$row['request_json'],$json) ||
               !hash_equals((string)$row['staging_root'],$root)){
                throw new RuntimeException('plugin_worker_queue_integrity_failed');
            }
            // Revalidate live (read-only) tenant consent, package registry
            // state, entrypoint digest and full package tree before scanning.
            SiteScopedPluginControlPlane::assess($siteDb,$job,$root);
            if(!hash_equals($expectedImageDigest,$job['image_digest'])){
                return ['state'=>'blocked_image_pin_mismatch','request_id'=>$id];
            }
            // Static compatibility only; never execute PHP while deciding
            // whether the current isolated worker exposes required APIs.
            $compatibility=SiteScopedPluginCompatibility::inspect($root);
            if(!$compatibility['compatible']){
                return [
                    'state'=>'blocked_missing_capabilities',
                    'request_id'=>$id,
                    'missing_capabilities'=>$compatibility['missing_capabilities'],
                ];
            }
            try{
                $result=SiteScopedPluginControlPlane::executeApproved(
                    $siteDb,$ledger,$job,$root,$timeoutSeconds,
                    $expectedImageDigest,$dockerSocket,$workerHome
                );
                return [
                    'state'=>'completed','request_id'=>$id,
                    'exit'=>$result['exit'],'timeout'=>$result['timeout'],
                    'cleanup_verified'=>$result['cleanup_verified'],
                ];
            }catch(RuntimeException $error){
                if($error->getMessage()==='plugin_control_external_approval_missing'){
                    // No state transition, claim, Docker connection or retry.
                    return ['state'=>'waiting_external_approval','request_id'=>$id];
                }
                if(in_array($error->getMessage(),[
                    'plugin_worker_rootless_socket_required',
                    'plugin_worker_home_not_private',
                    'plugin_worker_rootless_daemon_required',
                    'plugin_worker_image_digest_mismatch',
                    'plugin_worker_docker_probe_unavailable',
                    'plugin_worker_docker_probe_failed',
                    'plugin_worker_docker_probe_timeout',
                    'plugin_control_rootless_worker_unavailable',
                    'plugin_control_worker_image_pin_mismatch',
                ],true)){
                    return ['state'=>'blocked_worker_runtime','request_id'=>$id];
                }
                throw $error;
            }
        }catch(Throwable $error){
            if($error instanceof RuntimeException)throw $error;
            throw new RuntimeException('plugin_worker_queue_read_failed');
        }
    }
}
