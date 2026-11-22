<?php
declare(strict_types=1);
/**
 * Candidate-only negative runtime QA: a fake Docker CLI "run" returns 0,
 * while inspection fails. This must NEVER be reported as a successful
 * sandbox execution. Only a private copy of the class is rewritten for QA.
 * No real Docker command, production file or network endpoint is used.
 */
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/cms-cleanup-fail-'.bin2hex(random_bytes(10));
if(!mkdir($tmp,0700,true))throw new RuntimeException('qa_fixture_unavailable');
$php=$tmp.'/sample-qa.php';
$stub=$tmp.'/fake-docker';
$copy=$tmp.'/SandboxQA.php';
try {
    file_put_contents($php,"<?php echo 'synthetic';\n");
    file_put_contents($stub, "#!/bin/sh\n".
        "case \"\$1\" in\n".
        "  run) echo synthetic_success; exit 0;;\n".
        "  rm) exit 1;;\n".
        "  ps) exit 1;;\n".
        "  *) exit 1;;\n".
        "esac\n");
    chmod($stub,0700);
    $text=file_get_contents($root.'/src/SiteScopedExtensionSandbox.php');
    if(!is_string($text) || substr_count($text,"'docker'")!==5){
        throw new RuntimeException('sandbox_docker_call_sites_changed');
    }
    // Rewrites ONLY a throwaway class copy, not the candidate source.
    $text=str_replace("'docker'","'".$stub."'",$text);
    file_put_contents($copy,$text);
    require $copy;
    $method=new ReflectionMethod(\VazinCMS\SiteScopedExtensionSandbox::class,'executeQaOnly');
    $packageDigest=\VazinCMS\SiteScopedExtensionSandbox::packageDigest($tmp);
    $result=$method->invoke(
        null,1,'sample-qa',$tmp,$php,hash_file('sha256',$php),
        $packageDigest,'sha256:'.str_repeat('a',64),5
    );
    if(($result['exit']??null)!==125 ||
       ($result['cleanup_verified']??true)!==false ||
       ($result['timeout']??true)!==false){
        throw new RuntimeException('uncertain_cleanup_incorrectly_accepted: '.json_encode($result));
    }
    echo "SITE_SANDBOX_CLEANUP_AMBIGUITY=PASS docker_run_0,inspection_failed,reported_exit_125\n";
} finally {
    foreach([$copy,$stub,$php] as $p)@unlink($p);
    @rmdir($tmp);
}
