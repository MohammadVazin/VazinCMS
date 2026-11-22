<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;
use Throwable;

/**
 * Candidate-only CLI sandbox prototype, NOT connected to ExtensionRuntime.
 *
 * An unreviewed PHP bundle is never loaded into the CMS FPM process. Its
 * complete SHA-pinned package tree is snapshotted and mounted read-only in a
 * disposable, non-root Docker process: no network, no inherited environment/DB credentials, no host root mount,
 * read-only filesystem, no capabilities, limited CPU/RAM/PIDs and no-new-
 * privileges. This does NOT guarantee complete sandboxing from kernel bugs.
 *
 * Explicit image digest and operator-approved staging root are REQUIRED.
 * Never call from an HTTP route or an automatic worker.
 */
final class SiteScopedExtensionSandbox
{
    /** Only snapshots created in this one-shot process may be removed.
     * @var array<string,array{dev:int,ino:int,uid:int}>
     */
    private static array $ownedSnapshots=[];

    /**
     * Deterministic tree hash matching the CMS extension registry checksum.
     * All sibling PHP files and assets are pinned together; symlinks,
     * special files and oversized bundles are rejected.
     */
    public static function packageDigest(string $directory):string
    {
        if(PHP_SAPI!=='cli'){
            throw new RuntimeException('tenant_sandbox_cli_only');
        }
        $root=realpath($directory);
        if(!is_string($root) || !is_dir($root) || is_link($directory)){
            throw new RuntimeException('tenant_sandbox_untrusted_bundle');
        }
        $files=[];$total=0;
        $iterator=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)
        );
        foreach($iterator as $entry){
            $path=$entry->getPathname();
            if($entry->isLink()){
                throw new RuntimeException('tenant_sandbox_bundle_symlink_denied');
            }
            if($entry->isDir())continue;
            if(!$entry->isFile()){
                throw new RuntimeException('tenant_sandbox_bundle_special_file_denied');
            }
            $relative=str_replace(DIRECTORY_SEPARATOR,'/',
                substr($path,strlen($root)+1));
            $parts=explode('/',$relative);
            if($relative==='' || str_contains($relative,'\\') ||
               preg_match('//u',$relative)!==1 ||
               str_starts_with($relative,'/') ||
               in_array('',$parts,true) || in_array('.',$parts,true) ||
               in_array('..',$parts,true)){
                throw new RuntimeException('tenant_sandbox_bundle_path_denied');
            }
            $size=$entry->getSize();
            $total+=$size;
            if(count($files)>=1024 || $size<0 || $size>16777216 ||
               $total>67108864){
                throw new RuntimeException('tenant_sandbox_bundle_too_large');
            }
            $digest=hash_file('sha256',$path);
            if(!is_string($digest)){
                throw new RuntimeException('tenant_sandbox_bundle_unreadable');
            }
            $files[$relative]=$digest;
        }
        if($files===[]){
            throw new RuntimeException('tenant_sandbox_bundle_empty');
        }
        ksort($files,SORT_STRING);
        $payload='';
        foreach($files as $path=>$digest){
            $payload.=$digest.'  '.$path."\n";
        }
        return hash('sha256',$payload);
    }

    /**
     * @return list<string> OS argument vector; no shell interpolation.
     * The whole SHA-pinned package directory is mounted read-only so normal
     * sibling includes (e.g. bootstrap.php -> core/Core.php) resolve inside
     * the sandbox without exposing any host directory beyond this bundle.
     */
    public static function command(
        int $siteId,string $bundleKey,string $stagingRoot,string $entrypoint,
        string $expectedSha256,string $expectedPackageSha256,string $imageId
    ):array {
        if(PHP_SAPI!=='cli'){
            throw new RuntimeException('tenant_sandbox_cli_only');
        }
        if($siteId<1 ||
           preg_match('/\A[a-z0-9][a-z0-9-]{1,78}\z/D',$bundleKey)!==1 ||
           preg_match('/\A[a-f0-9]{64}\z/D',$expectedSha256)!==1 ||
           preg_match('/\A[a-f0-9]{64}\z/D',$expectedPackageSha256)!==1 ||
           preg_match('/\Asha256:[a-f0-9]{64}\z/D',$imageId)!==1 ||
           preg_match('/\A[a-z0-9][a-z0-9-]{1,78}\.php\z/D',$entrypoint)!==1){
            throw new RuntimeException('tenant_sandbox_input_invalid');
        }
        $root=realpath($stagingRoot);
        $source=is_string($root)?realpath($root.'/'.$entrypoint):false;
        if(!is_string($root) || !is_string($source) ||
            !is_dir($root) || !is_file($source) ||
            is_link($stagingRoot) || is_link($root.'/'.$entrypoint) ||
            $source!==$root.'/'.$entrypoint ||
            !hash_equals($expectedSha256,(string)hash_file('sha256',$source)) ||
            !hash_equals($expectedPackageSha256,self::packageDigest($root))){
            throw new RuntimeException('tenant_sandbox_untrusted_bundle');
        }
        return [
            'docker','run','--rm','--pull=never',
            '--name=vazincms-sbox-'.bin2hex(random_bytes(12)),
            '--network=none','--read-only',
            '--cap-drop=ALL','--security-opt=no-new-privileges',
            '--pids-limit=32','--memory=128m','--cpus=0.5',
            '--user=65534:65534',
            '--tmpfs=/tmp:rw,noexec,nosuid,nodev,size=8m,mode=1777',
            '--mount=type=bind,source='.$root.
                ',target=/sandbox/package,readonly',
            '--workdir=/sandbox/package','--entrypoint=php',
            $imageId,
            '-d','allow_url_fopen=0',
            '-d','open_basedir=/sandbox/package:/tmp',
            '-d','disable_functions=exec,passthru,shell_exec,system,' .
                 'proc_open,popen,pcntl_exec,dl,putenv',
            '-d','display_errors=stderr',
            '/sandbox/package/'.$entrypoint,
        ];
    }

    /**
     * Build a minimal environment for a dedicated NON-ROOT worker and its
     * own rootless Docker daemon. Never fall back to /var/run/docker.sock:
     * access to the rootful socket is effectively host-root authority.
     *
     * @return array<string,string>
     */
    public static function rootlessEnvironment(string $dockerSocket,string $home):array
    {
        if(PHP_SAPI!=='cli' || !function_exists('posix_geteuid')){
            throw new RuntimeException('plugin_worker_posix_required');
        }
        $uid=posix_geteuid();
        if($uid<1 || $dockerSocket!=="/run/user/".$uid."/docker.sock" ||
           is_link($dockerSocket) || !file_exists($dockerSocket) ||
           filetype($dockerSocket)!=='socket' || fileowner($dockerSocket)!==$uid ||
           (fileperms($dockerSocket)&0077)!==0){
            throw new RuntimeException('plugin_worker_rootless_socket_required');
        }
        $homeReal=realpath($home);
        if(!is_string($homeReal) || !is_dir($homeReal) || is_link($home) ||
           fileowner($homeReal)!==$uid || (fileperms($homeReal)&0077)!==0){
            throw new RuntimeException('plugin_worker_home_not_private');
        }
        return [
            'PATH'=>'/usr/bin:/bin',
            'HOME'=>$homeReal,
            'XDG_RUNTIME_DIR'=>'/run/user/'.$uid,
            'DOCKER_HOST'=>'unix://'.$dockerSocket,
            'DOCKER_CONFIG'=>$homeReal.'/.docker',
        ];
    }

    /** @param list<string> $argv */
    private static function dockerProbe(array $argv,array $environment):string
    {
        $pipes=[];$proc=@proc_open($argv,[
            0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
        ],$pipes,null,$environment);
        if(!is_resource($proc))throw new RuntimeException('plugin_worker_docker_probe_unavailable');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $out='';$err='';$exit=null;$deadline=microtime(true)+8;
        try{
            while(true){
                $out.=stream_get_contents($pipes[1],4096);
                $err.=stream_get_contents($pipes[2],4096);
                if(strlen($out)>8192 || strlen($err)>8192){
                    throw new RuntimeException('plugin_worker_docker_probe_failed');
                }
                $status=proc_get_status($proc);
                if(!$status['running']){$exit=(int)$status['exitcode'];break;}
                if(microtime(true)>=$deadline){
                    @proc_terminate($proc,9);
                    throw new RuntimeException('plugin_worker_docker_probe_timeout');
                }
                usleep(50000);
            }
        }finally{
            fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);
        }
        if($exit!==0)throw new RuntimeException('plugin_worker_docker_probe_failed');
        return trim($out);
    }

    /**
     * Confirm the endpoint is actually a rootless daemon and that the
     * requested immutable image is already present. This runs only AFTER
     * independent plugin approval; no pull or build is ever attempted.
     * @return array<string,string>
     */
    public static function assertRootlessDaemon(
        string $dockerSocket,string $workerHome,string $imageDigest
    ):array {
        if(preg_match('/\Asha256:[a-f0-9]{64}\z/D',$imageDigest)!==1){
            throw new RuntimeException('plugin_worker_image_pin_invalid');
        }
        $environment=self::rootlessEnvironment($dockerSocket,$workerHome);
        $security=self::dockerProbe(
            ['docker','info','--format','{{json .SecurityOptions}}'],$environment
        );
        if(!str_contains(strtolower($security),'rootless')){
            throw new RuntimeException('plugin_worker_rootless_daemon_required');
        }
        $image=self::dockerProbe(
            ['docker','image','inspect','--format','{{.Id}}',$imageDigest],
            $environment
        );
        if(!hash_equals($imageDigest,$image)){
            throw new RuntimeException('plugin_worker_image_digest_mismatch');
        }
        return $environment;
    }

    /**
     * Run only through a dedicated user's rootless daemon. This deliberately
     * refuses the host's rootful Docker socket even if the caller is root.
     * @return array{exit:int,stdout:string,stderr:string,timeout:bool,cleanup_verified:?bool}
     */
    public static function executeRootless(
        int $siteId,string $bundleKey,string $stagingRoot,string $entrypoint,
        string $expectedSha256,string $expectedPackageSha256,string $imageId,
        string $dockerSocket,string $workerHome,int $timeoutSeconds=7
    ):array {
        if(PHP_SAPI!=='cli' || $timeoutSeconds<1 || $timeoutSeconds>30){
            throw new RuntimeException('tenant_sandbox_execution_denied');
        }
        $environment=self::assertRootlessDaemon($dockerSocket,$workerHome,$imageId);
        $root=realpath($stagingRoot);$source=realpath($entrypoint);
        $name=basename($entrypoint);
        if(!is_string($root) || !is_string($source) ||
           $source!==$root.DIRECTORY_SEPARATOR.$name || is_link($entrypoint)){
            throw new RuntimeException('tenant_sandbox_untrusted_bundle');
        }
        $argv=self::command(
            $siteId,$bundleKey,$root,$name,
            $expectedSha256,$expectedPackageSha256,$imageId
        );
        $pinned=self::pinnedPackage($stagingRoot,$expectedPackageSha256);
        try{
            return self::runPinned($argv,$pinned,$timeoutSeconds,$environment);
        }finally{
            self::removePinnedPackage($pinned);
        }
    }

    /** 
     * Invoke only from explicitly approved CLI QA or a future reviewed
     * isolated control plane. Output is bounded and timeout is enforced.
     * @return array{exit:int,stdout:string,stderr:string,timeout:bool,cleanup_verified:?bool}
     */
    private static function executeQaOnly(
        int $siteId,string $bundleKey,string $stagingRoot,string $entrypoint,
        string $expectedSha256,string $expectedPackageSha256,
        string $imageId,int $timeoutSeconds=7
    ):array {
        if(PHP_SAPI!=='cli' || $timeoutSeconds<1 || $timeoutSeconds>30){
            throw new RuntimeException('tenant_sandbox_execution_denied');
        }
        $root=realpath($stagingRoot);$source=realpath($entrypoint);
        $name=basename($entrypoint);
        if(!is_string($root) || !is_string($source) ||
           $source!==$root.DIRECTORY_SEPARATOR.$name || is_link($entrypoint)){
            throw new RuntimeException('tenant_sandbox_untrusted_bundle');
        }
        $argv=self::command(
            $siteId,$bundleKey,$root,$name,
            $expectedSha256,$expectedPackageSha256,$imageId
        );
        $pinned=self::pinnedPackage($root,$expectedPackageSha256);
        try {
            return self::runPinned($argv,$pinned,$timeoutSeconds);
        } finally {
            self::removePinnedPackage($pinned);
        }
    }

    /** @param list<string> $argv
     *  @return array{exit:int,stdout:string,stderr:string,timeout:bool,cleanup_verified:?bool}
     */
    private static function runPinned(array $argv,string $pinned,int $timeoutSeconds,array $environment=[]):array
    {
        $mounts=0;
        foreach($argv as &$argument){
            if(str_starts_with($argument,'--mount=type=bind,source=') &&
               str_ends_with($argument,',target=/sandbox/package,readonly')){
                $argument='--mount=type=bind,source='.$pinned.
                    ',target=/sandbox/package,readonly';
                $mounts++;
            }
        }
        unset($argument);
        if($mounts!==1){
            throw new RuntimeException('tenant_sandbox_mount_invalid');
        }
        $names=array_values(array_filter($argv,static fn(string $arg):bool=>
            str_starts_with($arg,'--name=vazincms-sbox-')
        ));
        if(count($names)!==1 ||
           preg_match('/^--name=vazincms-sbox-[a-f0-9]{24}$/D',
                      $names[0])!==1){
            throw new RuntimeException('tenant_sandbox_container_name_invalid');
        }
        $containerName=substr($names[0],7);
        $descriptor=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc=@proc_open($argv,$descriptor,$pipes,null,$environment);
        if(!is_resource($proc)){
            throw new RuntimeException('tenant_sandbox_runtime_unavailable');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);
        stream_set_blocking($pipes[2],false);
        $stdout='';$stderr='';$timedOut=false;
        $deadline=microtime(true)+$timeoutSeconds;
        $exitCode=null;
        try{
            while(true){
                $stdout.=stream_get_contents($pipes[1],16384);
                $stderr.=stream_get_contents($pipes[2],16384);
                if(strlen($stdout)>65536 || strlen($stderr)>65536){
                    $timedOut=true;
                    break;
                }
                $status=proc_get_status($proc);
                if(!$status['running']){
                    $exitCode=(int)$status['exitcode'];
                    break;
                }
                if(microtime(true)>=$deadline){
                    $timedOut=true;
                    break;
                }
                usleep(100000);
            }
        }finally{
            if($timedOut){
                @proc_terminate($proc,9);
            }
            $stdout.=stream_get_contents($pipes[1],8192);
            $stderr.=stream_get_contents($pipes[2],8192);
            fclose($pipes[1]);fclose($pipes[2]);
            proc_close($proc);
        }
        // Even a non-timeout Docker exit is not evidence that the
        // container is absent. Always force-remove ONLY this unique random
        // name and then independently list/verify its absence. An unknown
        // daemon response is never called clean.
        $cleanupVerified=self::forceRemoveAndVerify($containerName,$environment);
        // A zero exit from the Docker CLI is not an acceptable success
        // if the container's removal cannot be independently confirmed.
        // Return a distinct failure code for ambiguous cleanup; never
        // silently advertise a successful sandbox execution.
        return [
            'exit'=>$timedOut?124:(!$cleanupVerified?125:(int)($exitCode??1)),
            'stdout'=>substr($stdout,0,65536),
            'stderr'=>substr($stderr,0,65536),
            'timeout'=>$timedOut,
            'cleanup_verified'=>$cleanupVerified,
        ];
    }

    /**
     * Freeze the complete reviewed package into a private snapshot. Every
     * regular file is copied, symlinks/special files are rejected, and the
     * deterministic tree hash is checked AFTER copying to defeat TOCTOU.
     */
    private static function pinnedPackage(
        string $sourceRoot,string $expectedPackageSha256
    ):string {
        $root=realpath($sourceRoot);
        if(!is_string($root) || !is_dir($root) || is_link($sourceRoot)){
            throw new RuntimeException('tenant_sandbox_snapshot_untrusted');
        }
        $folder=sys_get_temp_dir().'/vazincms-sbox-pin-'.
            bin2hex(random_bytes(16));
        if(!@mkdir($folder,0700)){
            throw new RuntimeException('tenant_sandbox_snapshot_unavailable');
        }
        $snapshot=$folder.'/package';
        clearstatcache(true,$folder);
        $identity=@lstat($folder);
        if(!is_array($identity) || ($identity['mode']&0170000)!==0040000){
            @rmdir($folder);
            throw new RuntimeException('tenant_sandbox_snapshot_unavailable');
        }
        self::$ownedSnapshots[$snapshot]=[
            'dev'=>(int)$identity['dev'],
            'ino'=>(int)$identity['ino'],
            'uid'=>(int)$identity['uid'],
        ];
        try{
            if(!@mkdir($snapshot,0700)){
                throw new RuntimeException('tenant_sandbox_snapshot_unavailable');
            }
            $iterator=new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach($iterator as $entry){
                $source=$entry->getPathname();
                $relative=substr($source,strlen($root)+1);
                $target=$snapshot.DIRECTORY_SEPARATOR.$relative;
                if($entry->isLink()){
                    throw new RuntimeException('tenant_sandbox_snapshot_untrusted');
                }
                if($entry->isDir()){
                    if(!is_dir($target) && !@mkdir($target,0700,true)){
                        throw new RuntimeException('tenant_sandbox_snapshot_unavailable');
                    }
                    continue;
                }
                if(!$entry->isFile() || $entry->getSize()>16777216){
                    throw new RuntimeException('tenant_sandbox_snapshot_untrusted');
                }
                if(!is_dir(dirname($target)) &&
                   !@mkdir(dirname($target),0700,true)){
                    throw new RuntimeException('tenant_sandbox_snapshot_unavailable');
                }
                if(!@copy($source,$target) || is_link($target) ||
                   !@chmod($target,0444)){
                    throw new RuntimeException('tenant_sandbox_snapshot_untrusted');
                }
            }
            if(!hash_equals($expectedPackageSha256,self::packageDigest($snapshot))){
                throw new RuntimeException('tenant_sandbox_snapshot_untrusted');
            }
            $directories=[];
            $dirs=new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($snapshot,\FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach($dirs as $entry){
                if($entry->isDir() && !$entry->isLink()){
                    $directories[]=$entry->getPathname();
                }
            }
            foreach($directories as $directory){
                if(!@chmod($directory,0555)){
                    throw new RuntimeException('tenant_sandbox_snapshot_untrusted');
                }
            }
            if(!@chmod($snapshot,0555)){
                throw new RuntimeException('tenant_sandbox_snapshot_untrusted');
            }
            return $snapshot;
        }catch(Throwable $error){
            self::removePinnedPackage($snapshot);
            @rmdir($folder);
            throw $error;
        }
    }

    /**
     * Remove only this process's own snapshot. A non-root owner must restore
     * write permission on EACH directory BEFORE unlinking its children.
     * Never chmod a file/symlink: chmod follows symlinks and also changes
     * hard-linked objects outside the snapshot. Deletion needs only the
     * containing directory's permissions, not writable file contents.
     * A partial/unknown cleanup throws; callers cannot report success.
     */
    private static function removePinnedPackage(string $snapshot):void
    {
        $owned=self::$ownedSnapshots[$snapshot]??null;
        if(!is_array($owned)){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_target_invalid');
        }
        $parent=dirname($snapshot);
        clearstatcache(true,$parent);
        $current=@lstat($parent);
        if(!is_array($current) || ($current['mode']&0170000)!==0040000 ||
           (int)$current['dev']!==$owned['dev'] ||
           (int)$current['ino']!==$owned['ino'] ||
           (int)$current['uid']!==$owned['uid']){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_target_changed');
        }
        if(!@chmod($parent,0700)){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
        }
        $remaining=16384;
        self::removeSnapshotEntry($snapshot,$remaining);
        // Do not remove unexpected siblings. A non-empty parent is a failed
        // cleanup, not permission to delete other files in that directory.
        if(!@rmdir($parent)){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
        }
        clearstatcache(true,$snapshot);clearstatcache(true,$parent);
        if(@lstat($snapshot)!==false || @lstat($parent)!==false){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
        }
        unset(self::$ownedSnapshots[$snapshot]);
    }

    /** lstat and unlink do not follow symlinks. Never recurse through one. */
    private static function removeSnapshotEntry(string $path,int &$remaining):void
    {
        if(--$remaining<0){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
        }
        clearstatcache(true,$path);
        $stat=@lstat($path);
        if($stat===false)return; // a failed snapshot may not have created it
        if(($stat['mode']&0170000)===0040000){
            if(!@chmod($path,0700)){
                throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
            }
            clearstatcache(true,$path);
            $after=@lstat($path);
            if(!is_array($after) || ($after['mode']&0170000)!==0040000 ||
               $after['dev']!==$stat['dev'] || $after['ino']!==$stat['ino']){
                throw new RuntimeException('tenant_sandbox_snapshot_cleanup_target_changed');
            }
            $children=@scandir($path);
            if(!is_array($children)){
                throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
            }
            foreach($children as $child){
                if($child!=='.' && $child!=='..'){
                    self::removeSnapshotEntry($path.DIRECTORY_SEPARATOR.$child,$remaining);
                }
            }
            if(!@rmdir($path)){
                throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
            }
        }elseif(!@unlink($path)){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
        }
        clearstatcache(true,$path);
        if(@lstat($path)!==false){
            throw new RuntimeException('tenant_sandbox_snapshot_cleanup_unverified');
        }
    }

    /**
     * On timeout, verify that OUR random container name no longer exists.
     * A successful docker rm receipt alone is not accepted as cleanup proof.
     * Never remove any caller-supplied or non-matching container name.
     */
    private static function forceRemoveAndVerify(string $name,array $environment=[]):bool
    {
        if(preg_match('/^vazincms-sbox-[a-f0-9]{24}$/D',$name)!==1){
            return false;
        }
        $commands=[
            ['docker','rm','--force',$name],
            ['docker','ps','--all','--filter','name=^/'.$name.'$',
             '--format','{{.Names}}'],
        ];
        foreach($commands as $index=>$command){
            $descriptor=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
            $proc=@proc_open($command,$descriptor,$pipes,null,$environment);
            if(!is_resource($proc))return false;
            fclose($pipes[0]);
            stream_set_blocking($pipes[1],false);
            stream_set_blocking($pipes[2],false);
            $output='';$error='';$exit=null;$deadline=microtime(true)+7;
            while(true){
                $output.=stream_get_contents($pipes[1],4096);
                $error.=stream_get_contents($pipes[2],4096);
                $status=proc_get_status($proc);
                if(!$status['running']){
                    $exit=(int)$status['exitcode'];
                    break;
                }
                if(microtime(true)>=$deadline){
                    @proc_terminate($proc,9);
                    break;
                }
                usleep(100000);
            }
            $output.=stream_get_contents($pipes[1],4096);
            fclose($pipes[1]);fclose($pipes[2]);
            proc_close($proc);
            if($index===1){
                // docker ps failure cannot be confused with "zero matches".
                return $exit===0 && trim($output)==='';
            }
            // On step 0 "no such container" can mean --rm already finished;
            // do not rely on rm's status. Verify with the next listing.
        }
        return false;
    }
}
