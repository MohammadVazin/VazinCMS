<?php
declare(strict_types=1);
/**
 * Pure in-memory SQLite negative acceptance; no production connections.
 * A database "verified_at" row is not external domain-control evidence.
 */
require dirname(__DIR__).'/src/SiteScopedReleaseGate.php';
use VazinCMS\SiteScopedReleaseGate as Gate;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, status TEXT, role TEXT)");
$db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY, owner_user_id INTEGER, status TEXT)");
$db->exec("CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT)");
$db->exec("CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,is_primary INTEGER,verified_at TEXT)");
$must=static function(bool $ok,string $name):void{
    if(!$ok)throw new RuntimeException('SITE_OWNER_DOMAIN_CONSISTENCY_FAIL '.$name);
};
$must(!Gate::ownerDomainDatabaseComplete($db),'empty_false');
$db->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','owner')");
$db->exec("INSERT INTO cms_sites VALUES(1,1,'active'),(2,2,'active')");
$db->exec("INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(2,2,'owner')");
$db->exec("INSERT INTO cms_site_domains VALUES(1,'a.qa.invalid',1,'qa'),(2,'b.qa.invalid',1,'qa')");
$must(Gate::ownerDomainDatabaseComplete($db),'valid_db_contract');
$db->exec("DELETE FROM cms_site_memberships WHERE site_id=2");
$db->exec("INSERT INTO cms_site_memberships VALUES(2,1,'owner')");
$must(!Gate::ownerDomainDatabaseComplete($db),'cross_site_owner_mismatch');
$db->exec("DELETE FROM cms_site_memberships WHERE site_id=2");
$db->exec("INSERT INTO cms_site_memberships VALUES(2,2,'owner'),(2,1,'owner')");
$must(!Gate::ownerDomainDatabaseComplete($db),'two_conflicting_site_owners');
$db->exec("DELETE FROM cms_site_memberships WHERE site_id=2 AND user_id=1");
$db->exec("UPDATE cms_site_domains SET verified_at=NULL WHERE site_id=2");
$must(!Gate::ownerDomainDatabaseComplete($db),'unverified_domain');
$db->exec("UPDATE cms_site_domains SET verified_at='qa',is_primary=0 WHERE site_id=2");
$must(!Gate::ownerDomainDatabaseComplete($db),'missing_primary_domain');
$db->exec("UPDATE cms_site_domains SET is_primary=1 WHERE site_id=2");
$db->exec("INSERT INTO cms_site_domains VALUES(2,'second.qa.invalid',1,'qa')");
$must(!Gate::ownerDomainDatabaseComplete($db),'two_primary_domains');
$db->exec("DELETE FROM cms_site_domains WHERE domain='second.qa.invalid'");
$db->exec("UPDATE cms_sites SET status='suspended' WHERE id=2");
$must(!Gate::ownerDomainDatabaseComplete($db),'suspended_site');
$db->exec("UPDATE cms_sites SET status='active' WHERE id=2");
$db->exec("UPDATE users SET status='disabled' WHERE id=2");
$must(!Gate::ownerDomainDatabaseComplete($db),'disabled_owner');
$db->exec("UPDATE users SET status='active' WHERE id=2");
$must(Gate::ownerDomainDatabaseComplete($db),'recovery_of_synthetic_fixture');
$db->exec("DELETE FROM cms_sites WHERE id=2");
$must(!Gate::ownerDomainDatabaseComplete($db),'single_site_never_multisite_ready');
echo "SITE_OWNER_DOMAIN_DB_CONSISTENCY=PASS mismatched_owner,second_owner,unverified,primary_ambiguity,suspended,disabled,single_site\n";
