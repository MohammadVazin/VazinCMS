<?php
declare(strict_types=1);
/** Synthetic SQLite only: staging domain updates must be atomic and never
 * grant verified_at or reach a public DNS/HTTP verifier.
 */
$root=dirname(__DIR__);
foreach(['DatabaseTransaction','SiteDomainDnsClaim','MultisiteManager'] as $name) {
    require_once $root.'/src/'.$name.'.php';
}
use VazinCMS\MultisiteManager as Sites;
$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db->exec("PRAGMA foreign_keys=ON");
$db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,status TEXT NOT NULL)");
$db->exec("CREATE TABLE cms_site_domains(site_id INTEGER NOT NULL REFERENCES cms_sites(id),domain TEXT UNIQUE NOT NULL,is_primary INTEGER NOT NULL DEFAULT 0,verification_token TEXT,verified_at TEXT)");
$db->exec("INSERT INTO cms_sites VALUES(1,'active'),(2,'suspended')");
$need=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('DOMAIN_STAGING_TX_FAILED:'.$why);
};
$primary=Sites::addDomain($db,1,'WWW.Example.ORG',true);
$need($primary['domain']==='www.example.org' &&
    str_starts_with($primary['verification_token'],'vzdom_'),'initial_primary');
$assertRows=static function() use($db,$need):void{
    $rows=$db->query("SELECT domain,is_primary,verified_at FROM cms_site_domains ORDER BY domain")->fetchAll();
    $need(count(array_filter($rows,static fn($v)=>$v['is_primary']==1))===1,
        'ambiguous_primary_after_transaction');
    foreach($rows as $row)$need($row['verified_at']===null,'premature_domain_verification');
};
$assertRows();
$fail=false;
try {Sites::addDomain($db,1,'www.example.org',true);}
catch(PDOException){$fail=true;}
$need($fail,'duplicate_domain_not_denied');
$assertRows();
$need((int)$db->query("SELECT is_primary FROM cms_site_domains WHERE domain='www.example.org'")->fetchColumn()===1,
    'rollback_cleared_previous_primary');
$fail=false;
try {Sites::addDomain($db,2,'suspended.example.org',true);}
catch(InvalidArgumentException $e){$fail=$e->getMessage()==='inactive_or_unknown_site';}
$need($fail,'suspended_site_not_denied');
foreach(['user@evil.com','127.0.0.1','evil.com:8080','*.example.org','localhost'] as $bad){
    $fail=false;
    try {Sites::addDomain($db,1,$bad,true);}
    catch(InvalidArgumentException){$fail=true;}
    $need($fail,'bad_host_accepted:'.$bad);
}
$second=Sites::addDomain($db,1,'shop.example.org',true);
$assertRows();
$need((int)$db->query("SELECT is_primary FROM cms_site_domains WHERE domain='www.example.org'")->fetchColumn()===0,
    'previous_primary_was_not_demoted');
$need((int)$db->query("SELECT is_primary FROM cms_site_domains WHERE domain='shop.example.org'")->fetchColumn()===1,
    'new_primary_was_not_promoted');
echo "SITE_DOMAIN_STAGING_ATOMIC=PASS valid_host,unknown_site_denied,duplicate_rollback,one_primary,never_verified,non_networked\n";
