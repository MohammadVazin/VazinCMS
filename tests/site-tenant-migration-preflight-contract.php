<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/SiteTenantMigrationReadiness.php';
use VazinCMS\SiteTenantMigrationReadiness;

$assert=static function(bool $condition,string $what):void {
    if(!$condition)throw new RuntimeException('SITE_TENANT_PREFLIGHT_TEST_FAIL '.$what);
};
$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT,owner_user_id INTEGER);");
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);");
$db->exec("CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT);");
$db->exec("CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,verified_at TEXT,is_primary INTEGER);");
$db->exec("CREATE TABLE cms_media(id INTEGER PRIMARY KEY);");
$db->exec("INSERT INTO cms_sites VALUES(1,'default','active',NULL);");
$db->exec("INSERT INTO users VALUES(1,'owner','active');");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'] &&
    in_array('missing_explicit_site_owner_binding',$a['blockers'],true) &&
    in_array('verified_canonical_domain_missing',$a['blockers'],true),
    'missing owner and verified domain must reject');
$db->exec("UPDATE cms_sites SET owner_user_id=1 WHERE id=1;");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'] &&
    in_array('owner_membership_requires_explicit_binding',$a['blockers'],true),
    'global owner role must not authorize tenant');
$db->exec("INSERT INTO cms_site_memberships VALUES(1,1,'owner');");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'] &&
    in_array('verified_canonical_domain_missing',$a['blockers'],true),
    'unverified domain must reject');
$db->exec("INSERT INTO cms_site_domains VALUES(1,'secondary.test','2026-11-01 00:00:00',0);");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'] &&
    in_array('verified_canonical_domain_missing',$a['blockers'],true),
    'secondary verified domain must not satisfy primary host');
$db->exec("INSERT INTO cms_site_domains VALUES(1,'cms.test',NULL,1);");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'],
    'primary domain needs verification marker');
$db->exec("UPDATE cms_site_domains SET verified_at='2026-11-01 00:00:00' WHERE domain='cms.test';");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert($a['schema_proposal_review_ready']===true,'fully explicit single-site preflight');
$db->exec("INSERT INTO users VALUES(2,'owner','active');");
$db->exec("INSERT INTO cms_site_memberships VALUES(1,2,'owner');");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'] &&
    in_array('owner_membership_requires_explicit_binding',$a['blockers'],true),
    'ambiguous owner membership must fail closed');
$db->exec("DELETE FROM cms_site_memberships WHERE user_id=2;");
$db->exec("INSERT INTO cms_site_domains VALUES(1,'duplicate-primary.test','2026-11-01 00:00:00',1);");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'],
    'two primary domains must fail closed');
$db->exec("DELETE FROM cms_site_domains WHERE domain='duplicate-primary.test';");
$db->exec("INSERT INTO cms_media VALUES(1);");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'] &&
    in_array('legacy_media_path_migration_restore_drill_required',$a['blockers'],true),
    'existing media must require file migration/restore');
$db->exec("DELETE FROM cms_media;");
$db->exec("INSERT INTO cms_sites VALUES(2,'beta','active',2);");
$a=SiteTenantMigrationReadiness::inspect($db);
$assert(!$a['schema_proposal_review_ready'] &&
    in_array('requires_exactly_one_active_default_site',$a['blockers'],true),
    'ambiguous site count must reject');
echo "SITE_TENANT_MIGRATION_PREFLIGHT=PASS explicit_owner,membership,domain,media,two_site_denial\n";
