<?php
declare(strict_types=1);
/** Candidate-only, in-memory negative GO acceptance. No signer and no deploy. */
$root=dirname(__DIR__);
require $root.'/src/SiteScopedReleaseGate.php';
use VazinCMS\SiteScopedReleaseGate as Gate;
$must=static function(bool $v,string $reason):void{
    if(!$v)throw new RuntimeException('MULTISITE_APPROVAL_BOUNDARY_FAIL:'.$reason);
};
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT)");
$db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,owner_user_id INTEGER,status TEXT)");
$db->exec("CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT)");
$db->exec("CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,is_primary INTEGER,verified_at TEXT)");
$db->exec("INSERT INTO users VALUES(1,'active'),(2,'active')");
$db->exec("INSERT INTO cms_sites VALUES(1,1,'active'),(2,2,'active')");
$db->exec("INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(2,2,'owner')");
$db->exec("INSERT INTO cms_site_domains VALUES(1,'a.qa.invalid',1,'synthetic'),(2,'b.qa.invalid',1,'synthetic')");
$must(Gate::ownerDomainDatabaseComplete($db),'db_positive_fixture_invalid');
$prior=getenv('VAZIN_RELEASE_GO');
try{
    // A forged environment hint cannot replace a signed owner approval.
    putenv('VAZIN_RELEASE_GO=1');
    $r=Gate::assess($root,$db);
    $must(($r['observations']['owner_domain_database_consistent']??false)===true,
        'db_fixture_was_not_checked');
    $must(($r['checks']['verified_owner_and_domain']??true)===false,
        'synthetic_verified_at_bypassed_external_domain_proof');
    $must($r['release_decision']==='NO_GO','synthetic_db_unlocked_release');
    $must($r['checks']['external_release_signature_verified']===false,
        'self_signed_placeholder_approved');
    $must(in_array('signed_multisite_isolation_release_approval_missing',
          $r['blockers'],true),'explicit_owner_go_bypassed');
    $must($r['production_actions_performed']===false,
        'reported_production_write');
} finally {
    if($prior===false)putenv('VAZIN_RELEASE_GO');
    else putenv('VAZIN_RELEASE_GO='.$prior);
}
echo "MULTISITE_SIGNED_APPROVAL_BOUNDARY=PASS synthetic_two_sites_db_only,forged_go_denied,NO_GO\n";
