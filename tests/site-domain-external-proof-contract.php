<?php
declare(strict_types=1);
/**
 * Candidate-only negative contract. No live DNS, production DB or HTTP.
 * The old token-only path must be unable to mark a domain as verified.
 */
require dirname(__DIR__).'/src/MultisiteManager.php';
use VazinCMS\MultisiteManager;

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE cms_site_domains (site_id INTEGER,domain TEXT,verification_token TEXT,verified_at TEXT)");
$pdo->exec("INSERT INTO cms_site_domains VALUES (1,'example.invalid','vzdom_known_fixture',NULL)");
foreach(['vzdom_known_fixture','vzdom_wrong',''] as $token){
    try {
        MultisiteManager::verifyDomain($pdo,'example.invalid',$token);
        throw new RuntimeException('DOMAIN_TOKEN_ALONE_ALLOWED');
    } catch (InvalidArgumentException $e) {
        if($e->getMessage()!=='domain_external_proof_required'){
            throw new RuntimeException('UNEXPECTED_DENIAL_REASON');
        }
    }
    $stmt=$pdo->query("SELECT verified_at FROM cms_site_domains WHERE site_id=1");
    $verified=$stmt->fetchColumn();
    $stmt->closeCursor();
    if($verified!==null)throw new RuntimeException('UNAUTHORIZED_DOMAIN_VERIFIED');
}
$code=file_get_contents(dirname(__DIR__).'/src/MultisiteManager.php');
if(str_contains($code,'SET verified_at=CURRENT_TIMESTAMP') ||
   !str_contains($code,'domain_external_proof_required')){
    throw new RuntimeException('TOKEN_ONLY_DOMAIN_WRITER_STILL_PRESENT');
}
echo "DOMAIN_EXTERNAL_PROOF_REQUIRED=PASS valid_token_denied,no_database_write,bad_token_denied,unknown_proof_denied\n";
