<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteScopedExtensionSandbox.php';
use VazinCMS\SiteScopedExtensionSandbox as Sandbox;
$qaMethod=new ReflectionMethod(Sandbox::class,'executeQaOnly');
$workerMethod=new ReflectionMethod(Sandbox::class,'executeRootless');
if($qaMethod->isPublic() || !$workerMethod->isPublic()){
    throw new RuntimeException('rootful_qa_path_is_public_or_rootless_path_missing');
}
$uid=posix_geteuid();
if($uid===0){
    try{
        Sandbox::rootlessEnvironment('/var/run/docker.sock','/root');
    }catch(RuntimeException $e){
        if($e->getMessage()==='plugin_worker_rootless_socket_required'){
            echo "PLUGIN_ROOTLESS_DOCKER_GATE=PASS rootful_socket_denied_for_root_worker\n";
            exit(0);
        }
        throw $e;
    }
    throw new RuntimeException('rootful_docker_socket_accepted');
}
$expected='/run/user/'.$uid.'/docker.sock';
try{
    Sandbox::rootlessEnvironment('/var/run/docker.sock',getenv('HOME')?:'/');
}catch(RuntimeException $e){
    if($e->getMessage()==='plugin_worker_rootless_socket_required'){
        echo "PLUGIN_ROOTLESS_DOCKER_GATE=PASS rootful_socket_denied\n";
        exit(0);
    }
    throw $e;
}
throw new RuntimeException('rootful_docker_socket_accepted');
