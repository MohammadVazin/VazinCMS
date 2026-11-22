<?php
declare(strict_types=1);

/**
 * Read-only candidate release preflight.
 *
 * Exit 3 means expected NO-GO; never deploy or change production from here.
 */
require dirname(__DIR__).'/src/SiteScopedReleaseGate.php';
$root=realpath(dirname(__DIR__));
if($root===false){
    fwrite(STDERR,"release root unavailable\n");
    exit(4);
}
try {
    $result=\VazinCMS\SiteScopedReleaseGate::assess($root);
    echo json_encode($result,
        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit(($result['release_decision']??'NO_GO')==='GO'?0:3);
} catch(\Throwable) {
    fwrite(STDERR,"release readiness could not be verified\n");
    exit(4);
}
