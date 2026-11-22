<?php
declare(strict_types=1);
/** Synthetic local-only tenant creation, fail-closed owner and rollback QA. */
$root=dirname(__DIR__);
foreach(['DatabaseTransaction','SiteDomainDnsClaim','MultisiteManager'] as $name){
    require_once $root.'/src/'.$name.'.php';
}
use VazinCMS\MultisiteManager as Sites;
$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY AUTOINCREMENT,site_key TEXT UNIQUE,name TEXT,status TEXT,owner_user_id INTEGER REFERENCES users(id),agency_key TEXT,default_locale TEXT,settings_json TEXT)');
$db->exec('CREATE TABLE cms_site_memberships(site_id INTEGER REFERENCES cms_sites(id),user_id INTEGER REFERENCES users(id),site_role TEXT,permissions_json TEXT,PRIMARY KEY(site_id,user_id))');
$db->exec("INSERT INTO users VALUES(1,'owner','active'),(2,'editor','active'),(3,'owner','suspended')");
$check=static function(bool $value,string $message):void{
    if(!$value)throw new RuntimeException('ATOMIC_TENANT_CREATE_FAILED:'.$message);
};
$id=Sites::createSite($db,'alpha-qa','Alpha Synthetic',1);
$check($id>0,'create_failed');
$rows=$db->query("SELECT s.owner_user_id,m.user_id,m.site_role FROM cms_sites s JOIN cms_site_memberships m ON m.site_id=s.id WHERE s.site_key='alpha-qa'")->fetchAll();
$check(count($rows)===1 && $rows[0]['owner_user_id']==1 &&
    $rows[0]['user_id']==1 && $rows[0]['site_role']==='owner',
    'owner_membership_not_atomic');
foreach([[0,'zero-owner'],[2,'not-owner'],[3,'suspended-owner']] as [$actor,$name]){
    $denied=false;
    try {Sites::createSite($db,'bad-'.$name,'Invalid Test', $actor);}
    catch(InvalidArgumentException){$denied=true;}
    $check($denied,'unauthorized_owner_allowed');
}
$db->exec("CREATE TRIGGER qa_deny_membership BEFORE INSERT ON cms_site_memberships WHEN NEW.site_id>1 BEGIN SELECT RAISE(ABORT,'synthetic membership write failed'); END");
$denied=false;
try { Sites::createSite($db,'orphan-qa','Must Roll Back',1); }
catch(PDOException){$denied=true;}
$check($denied,'injected_failure_not_raised');
$siteCount=(int)$db->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn();
$memberCount=(int)$db->query('SELECT COUNT(*) FROM cms_site_memberships')->fetchColumn();
$check($siteCount===1 && $memberCount===1,'orphan_site_left_after_failed_membership');
echo "SITE_TENANT_CREATE_ATOMIC=PASS explicit_active_owner,owner_membership,role_denials,fault_rollback,no_orphan\n";
