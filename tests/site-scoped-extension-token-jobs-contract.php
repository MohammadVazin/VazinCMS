<?php
declare(strict_types=1);

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='VazinCMS\\';
    if(!str_starts_with($class,$prefix))return;
    $path=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
use VazinCMS\{SiteScopedExtensionPreferences,SiteScopedApiTokenGrant,
    SiteScopedLocalJobQueue,SiteScopeMode};

$check=static function(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('SITE_PHASE7_FAIL: '.$label);
};
$denied=static function(callable $cb,string $expected,string $label)use($check):void{
    try{$cb();}catch(RuntimeException $ex){
        $check($ex->getMessage()===$expected,$label.' wrong code '.$ex->getMessage());
        return;
    }
    throw new RuntimeException('SITE_PHASE7_UNEXPECTED_SUCCESS: '.$label);
};

$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT,owner_user_id INTEGER);
CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,verified_at TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,
 PRIMARY KEY(site_id,user_id));
CREATE TABLE cms_extensions(
 extension_key TEXT PRIMARY KEY,extension_type TEXT,name TEXT,version TEXT,
 description TEXT,source TEXT,status TEXT);
CREATE TABLE cms_site_extension_preferences(
 site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 extension_key TEXT NOT NULL REFERENCES cms_extensions(extension_key),
 desired_enabled INTEGER NOT NULL,changed_by INTEGER NOT NULL REFERENCES users(id),
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(site_id,extension_key));
CREATE TABLE cms_site_theme_preferences(
 site_id INTEGER PRIMARY KEY REFERENCES cms_sites(id),
 extension_key TEXT NOT NULL REFERENCES cms_extensions(extension_key),
 requested_by INTEGER NOT NULL REFERENCES users(id),
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE api_tokens(
 id INTEGER PRIMARY KEY,created_by INTEGER,revoked_at TEXT,expires_at TEXT);
CREATE TABLE cms_site_api_token_grants(
 site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 token_id INTEGER NOT NULL UNIQUE REFERENCES api_tokens(id),
 granted_by INTEGER NOT NULL REFERENCES users(id),
 revoked_at TEXT,PRIMARY KEY(site_id,token_id));
CREATE TABLE cms_site_local_jobs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 job_key TEXT NOT NULL,task_type TEXT NOT NULL,is_active INTEGER,
 interval_minutes INTEGER,run_as_user_id INTEGER,next_run_at TEXT,
 last_run_at TEXT,last_status TEXT,locked_at TEXT,lock_token TEXT,
 created_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,job_key),UNIQUE(site_id,id));
CREATE TABLE cms_site_local_job_runs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER,job_id INTEGER,actor_id INTEGER,run_token TEXT UNIQUE,
 status TEXT,details_json TEXT,
 FOREIGN KEY(site_id,job_id) REFERENCES cms_site_local_jobs(site_id,id));
CREATE TABLE cms_site_job_claims(id INTEGER PRIMARY KEY,site_id INTEGER,job_id INTEGER,actor_id INTEGER,claim_token TEXT,state TEXT,result_json TEXT,review_note TEXT,reviewed_by INTEGER,claimed_at TEXT,finished_at TEXT,reviewed_at TEXT);
CREATE TABLE cms_site_worker_credentials(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,job_id INTEGER NOT NULL,actor_id INTEGER NOT NULL,issued_by INTEGER NOT NULL,token_prefix TEXT NOT NULL,token_hash TEXT NOT NULL UNIQUE,task_type TEXT NOT NULL,expires_at TEXT NOT NULL,issued_at TEXT DEFAULT CURRENT_TIMESTAMP,last_used_at TEXT,revoked_at TEXT,FOREIGN KEY(site_id,job_id) REFERENCES cms_site_local_jobs(site_id,id));
CREATE UNIQUE INDEX uq_qa_worker_live_job ON cms_site_worker_credentials(site_id,job_id) WHERE revoked_at IS NULL;
CREATE TABLE cms_pages(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER,slug TEXT,locale TEXT,content_type TEXT,title TEXT,body TEXT,
 status TEXT,editorial_state TEXT,trash_status TEXT,published_at TEXT,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,id));
CREATE TABLE cms_search_index(
 page_id INTEGER PRIMARY KEY,site_id INTEGER,locale TEXT,content_type TEXT,
 title TEXT,search_text TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE cms_site_editorial_events(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,page_id INTEGER,
 actor_id INTEGER,from_state TEXT,to_state TEXT,note TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id));
CREATE TABLE cms_settings(id INTEGER PRIMARY KEY,site_id INTEGER);
INSERT INTO cms_sites VALUES
 (1,'alpha','active',1),(2,'beta','active',2),(3,'suspended','suspended',4);
INSERT INTO cms_site_domains VALUES
 (1,'alpha.example.test','2026-11-02 00:00:00'),
 (2,'beta.example.test','2026-11-02 00:00:00'),
 (3,'inactive.example.test','2026-11-02 00:00:00');
INSERT INTO users VALUES
 (1,'owner','active'),(2,'admin','active'),(3,'client','active'),
 (4,'owner','suspended'),(5,'admin','active');
INSERT INTO cms_site_memberships VALUES
 (1,1,'owner'),(2,2,'admin'),(1,3,'author'),(3,4,'owner');
INSERT INTO cms_extensions VALUES
 ('vazin-default','theme','Default Theme','1.0.0','Bundled theme','bundled','active'),
 ('seo','module','SEO','1.0.0','Bundled SEO','bundled','active'),
 ('publishing','module','Publishing','1.0.0','Bundled publish','bundled','active'),
 ('untrusted','module','Unknown','1.0.0','Runtime plugin','runtime','inactive');
INSERT INTO api_tokens VALUES
 (11,1,NULL,NULL),(22,2,NULL,NULL),(33,4,NULL,NULL),(44,1,NULL,NULL);
INSERT INTO cms_pages(site_id,slug,locale,content_type,title,body,status,
 editorial_state,trash_status,published_at)
 VALUES
 (1,'a','fa','post','A page','A secret','draft','scheduled','active','2000-01-01 00:00:00'),
 (2,'b','fa','post','B page','B secret','draft','scheduled','active','2000-01-01 00:00:00');
SQL);
foreach([
'cms_media','cms_menu_items','cms_redirects','cms_taxonomies','cms_terms',
'cms_term_relationships','cms_page_revisions','cms_autosaves',
'cms_media_collections','cms_media_derivatives','cms_media_collection_items',
'cms_content_types','cms_forms','cms_form_fields','cms_form_submissions',
'cms_reusable_blocks','cms_block_patterns','cms_editorial_events',
'publication_jobs','cms_custom_field_definitions','cms_custom_field_values'
] as $table)$db->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
$check(SiteScopeMode::current($db)==='scoped','24 table precondition');

$a=new SiteScopedExtensionPreferences($db,1);
$b=new SiteScopedExtensionPreferences($db,2);
$check(count($a->catalog(1))===4,'extension catalog A');
$denied(fn()=>$b->catalog(1),'tenant_access_denied','A accessing B catalog');
$denied(fn()=>$a->catalog(3),'tenant_access_denied','author browsing site extensions');
$denied(fn()=>$a->requestModule(3,'seo',true),'tenant_access_denied','author module request');
$denied(fn()=>$a->requestModule(1,'untrusted',true),
    'tenant_extension_not_eligible','runtime plugin');
$denied(fn()=>$a->requestModule(1,'vazin-default',true),
    'tenant_extension_not_eligible','theme as module');
$a->requestModule(1,'seo',false);
$b->requestModule(2,'seo',true);
$a->requestTheme(1,'vazin-default');
$b->requestTheme(2,'vazin-default');
$ca=array_column($a->catalog(1),null,'extension_key');
$cb=array_column($b->catalog(2),null,'extension_key');
$check($ca['seo']['requested_enabled']===false &&
       $cb['seo']['requested_enabled']===true,'independent module choices');
$check($ca['vazin-default']['requested_theme'] &&
       $cb['vazin-default']['requested_theme'],'independent theme choices');
$check(!$ca['seo']['applied_to_runtime'] &&
       !$cb['vazin-default']['applied_to_runtime'],'preferences falsely applied to runtime');
$a->requestModule(1,'seo',true);
$check($cb['seo']['requested_enabled']===true,'B preference mutated');
$check($db->query("SELECT status FROM cms_extensions WHERE extension_key='seo'")
          ->fetchColumn()==='active','global extension status modified');
$check($db->query("SELECT COUNT(*) FROM cms_site_extension_preferences")
          ->fetchColumn()==2,'per-site preference row count');
echo "SITE_EXTENSION_PREFERENCES=PASS two_sites,roles,requested_state,inert_runtime,untrusted_rejected\n";

$at=new SiteScopedApiTokenGrant($db,1);
$bt=new SiteScopedApiTokenGrant($db,2);
$at->grant(1,11);
$bt->grant(2,22);
SiteScopedApiTokenGrant::assertCanUse($db,1,11,'alpha.example.test');
SiteScopedApiTokenGrant::assertCanUse($db,2,22,'beta.example.test');
$denied(fn()=>SiteScopedApiTokenGrant::assertCanUse($db,2,11,'beta.example.test'),
    'tenant_token_scope_denied','A token on B site');
$denied(fn()=>SiteScopedApiTokenGrant::assertCanUse($db,1,11,'unknown.example.test'),
    'tenant_token_scope_denied','host spoof');
$denied(fn()=>SiteScopedApiTokenGrant::assertCanUse($db,1,11,'alpha.example.test.evil'),
    'tenant_token_scope_denied','suffix spoof');
$denied(fn()=>$at->grant(3,44),'tenant_access_denied','author granting token');
$denied(fn()=>$bt->grant(2,11),'tenant_token_owner_denied','foreign owner token');
$db->exec("INSERT INTO cms_site_memberships VALUES(2,1,'admin')");
$denied(fn()=>$bt->grant(1,11),'tenant_token_cross_site_binding_denied',
    'shared actor trying cross-site token');
$db->exec("DELETE FROM cms_site_memberships WHERE site_id=2 AND user_id=1");
$at->revoke(1,11);
$denied(fn()=>SiteScopedApiTokenGrant::assertCanUse($db,1,11,'alpha.example.test'),
    'tenant_token_scope_denied','revoked token grant');
$at->grant(1,11);
$db->exec("DELETE FROM cms_site_memberships WHERE site_id=1 AND user_id=1");
$denied(fn()=>SiteScopedApiTokenGrant::assertCanUse($db,1,11,'alpha.example.test'),
    'tenant_token_scope_denied','revoked site membership');
$db->exec("INSERT INTO cms_site_memberships VALUES(1,1,'owner')");
$db->exec("UPDATE cms_site_domains SET verified_at=NULL WHERE site_id=1");
$denied(fn()=>SiteScopedApiTokenGrant::assertCanUse($db,1,11,'alpha.example.test'),
    'tenant_token_scope_denied','unverified domain');
$denied(fn()=>$at->grant(1,44),'tenant_verified_domain_required',
    'attempt grant with unverified domain');
$db->exec("UPDATE cms_site_domains SET verified_at='2026-11-02 00:00:00' WHERE site_id=1");
echo "SITE_API_TOKEN_GRANTS=PASS verified_host,explicit_actor,revocation,role_loss,cross_site_denied\n";

$qa=new SiteScopedLocalJobQueue($db,1);
$qb=new SiteScopedLocalJobQueue($db,2);
$ja=$qa->register(1,'publish-local',60,'2000-01-01 00:00:00',true);
$jb=$qb->register(2,'publish-local',60,'2000-01-01 00:00:00',true);
$check($ja!==$jb,'site job IDs duplicate');
$check(count($qa->list(1))===1&&count($qb->list(2))===1,'job listing scope');
$denied(fn()=>$qb->list(1),'tenant_access_denied','A reads B jobs');
$denied(fn()=>$qa->register(3,'author-job',60,'2000-01-01 00:00:00',true),
    'tenant_access_denied','author registers job');
$db->prepare("UPDATE cms_site_local_jobs SET lock_token='test-lock',locked_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$jb]);
$denied(fn()=>$qb->register(2,'publish-local',60,'2000-01-01 00:00:00',true),
    'tenant_job_locked_or_conflict','overwrite locked job');
$check($qb->runDue(2)===[],'locked B job attempted');
$first=$qa->runDue(1);
$check(count($first)===1 &&
       $first[0]['status']==='succeeded' &&
       $first[0]['published']===1 &&
       $first[0]['external_delivery']===false,
       'manual site A local publish');
$check($qa->runDue(1)===[],'double execution of A job');
$check($db->query("SELECT status FROM cms_pages WHERE site_id=1")->fetchColumn()==='published',
       'A not published');
$check($db->query("SELECT status FROM cms_pages WHERE site_id=2")->fetchColumn()==='draft',
       'B prematurely published');
$db->prepare("UPDATE cms_site_local_jobs SET lock_token=NULL,locked_at=NULL WHERE id=?")->execute([$jb]);
$second=$qb->runDue(2);
$check(count($second)===1 && $second[0]['published']===1,'B job publication missing');
$check($db->query('SELECT COUNT(*) FROM cms_site_local_job_runs')->fetchColumn()==2,
       'run logs not distinct');
$check($db->query('SELECT COUNT(*) FROM cms_search_index')->fetchColumn()==2,
       'search indexes not site-scoped');
$check($db->query('SELECT COUNT(*) FROM cms_site_editorial_events')->fetchColumn()==2,
       'editorial events lost');
$check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'foreign key errors');
$recoveryJob=$qa->register(1,'crash-recovery-qa',60,'2000-01-01 00:00:00',true);
$leaseRunner=new VazinCMS\SiteScopedJobLeaseRunner($db,1);
$lease=$leaseRunner->claimNext(1);
$check(is_array($lease)&&$lease['id']===$recoveryJob,'durable claim missing');
$check((int)$db->query("SELECT COUNT(*) FROM cms_site_job_claims WHERE state='claimed'")->fetchColumn()===1,
    'claim not stored before work');
$denied(fn()=>$leaseRunner->quarantineStale(1,$recoveryJob,$lease['token']),
    'tenant_claim_not_stale_or_owned','fresh worker lease quarantined');
$denied(fn()=>$leaseRunner->closeReviewed(
    1,$recoveryJob,$lease['token'],'not enough review',false),
    'tenant_review_attestation_required','unverified worker stop');
$db->prepare("UPDATE cms_site_local_jobs SET locked_at=datetime('now','-20 minutes') WHERE id=?")
   ->execute([$recoveryJob]);
$leaseRunner->quarantineStale(1,$recoveryJob,$lease['token']);
$pending=$leaseRunner->inspect(1);
$check(count($pending)===1 && $pending[0]['state']==='review_required',
    'stale claim not quarantined for manual review');
$denied(fn()=>$leaseRunner->executeClaim(1,$lease),
    'tenant_claim_not_active','quarantined worker execution');
$check($qa->runDue(1)===[],'quarantined job auto-retried');
$leaseRunner->closeReviewed(
    1,$recoveryJob,$lease['token'],
    'Operator verified worker process stopped; inspected content and job receipts.',
    true
);
$check($leaseRunner->inspect(1)===[],'closed claim still pending');
$reviewed=$db->prepare('SELECT is_active,last_status,locked_at,lock_token FROM cms_site_local_jobs WHERE id=?');
$reviewed->execute([$recoveryJob]);
$state=$reviewed->fetch(PDO::FETCH_ASSOC);
$check((int)$state['is_active']===0 && $state['last_status']==='interrupted_reviewed'
    && $state['lock_token']===null,'reviewed job silently reactivated');
$check((int)$db->query("SELECT COUNT(*) FROM cms_site_job_claims WHERE state='closed'")->fetchColumn()===1,
    'manual review history not retained');
echo "SITE_JOB_CRASH_RECOVERY=PASS claim_before_work,stale_quarantine,manual_close,no_auto_replay\n";
echo "SITE_LOCAL_JOB_QUEUE=PASS two_site_lock,idempotent,local_only,role_denied,run_receipts\n";
VazinCMS\SiteScopedSessionGuard::assertAdminAccess(
    $db,1,1,'alpha.example.test'
);
$denied(fn()=>VazinCMS\SiteScopedSessionGuard::assertAdminAccess(
    $db,1,1,'beta.example.test'
),'tenant_session_scope_denied','A session on B host');
$denied(fn()=>VazinCMS\SiteScopedSessionGuard::assertAdminAccess(
    $db,2,1,'beta.example.test'
),'tenant_session_scope_denied','A user without B membership');
$db->exec("UPDATE cms_site_memberships SET site_role='author' WHERE site_id=1 AND user_id=1");
$denied(fn()=>VazinCMS\SiteScopedSessionGuard::assertAdminAccess(
    $db,1,1,'alpha.example.test'
),'tenant_session_scope_denied','global owner with author-only site permission');
$db->exec("UPDATE cms_site_memberships SET site_role='owner' WHERE site_id=1 AND user_id=1");
$db->exec("DELETE FROM cms_site_memberships WHERE site_id=1 AND user_id=1");
$denied(fn()=>VazinCMS\SiteScopedSessionGuard::assertAdminAccess(
    $db,1,1,'alpha.example.test'
),'tenant_session_scope_denied','revoked tenant admin membership');
$db->exec("INSERT INTO cms_site_memberships VALUES(1,1,'owner')");
$db->exec("UPDATE cms_site_domains SET verified_at=NULL WHERE site_id=1");
$denied(fn()=>VazinCMS\SiteScopedSessionGuard::assertAdminAccess(
    $db,1,1,'alpha.example.test'
),'tenant_session_scope_denied','site domain no longer verified');
echo "SITE_ADMIN_MEMBERSHIP_GUARD=PASS exact_host,role_loss,verified_domain\n";
echo "SITE_PHASE7_CONTRACT=PASS no_external_actions=true production_untouched=true\n";
$db->exec("UPDATE cms_site_domains SET verified_at='2026-11-02 00:00:00' WHERE site_id=1");
$db->exec("UPDATE cms_site_memberships SET site_role='owner' WHERE site_id=2 AND user_id=2");

$db->exec("INSERT INTO cms_pages(site_id,slug,locale,content_type,title,body,status,
    editorial_state,trash_status,published_at)
 VALUES(1,'worker-a','fa','post','Worker QA Alpha','Synthetic Alpha',
    'draft','scheduled','active','2000-01-01 00:00:00')");
$db->exec("INSERT INTO cms_pages(site_id,slug,locale,content_type,title,body,status,
    editorial_state,trash_status,published_at)
 VALUES(2,'worker-b','fa','post','Worker QA Beta','Synthetic Beta',
    'draft','scheduled','active','2000-01-01 00:00:00')");

$jobA=$qa->register(1,'token-bound-job-a',60,'2000-01-01 00:00:00',true);
$otherJobA=$qa->register(1,'other-due-job-a',60,'1999-01-01 00:00:00',true);
$jobB=$qb->register(2,'token-bound-job-b',60,'2000-01-01 00:00:00',true);
$workerA=new VazinCMS\SiteScopedWorkerCredential($db,1);
$workerB=new VazinCMS\SiteScopedWorkerCredential($db,2);
$grantA=$workerA->issue(1,$jobA,24);
$grantB=$workerB->issue(2,$jobB,24);

$check(str_starts_with($grantA['token'],'vwc_') && strlen($grantA['token'])===68,
    'worker token format incorrect');
$hashA=hash('sha256',$grantA['token']);
$storedHash=$db->query("SELECT token_hash FROM cms_site_worker_credentials WHERE site_id=1")
    ->fetchColumn();
$check($storedHash===$hashA && !str_contains((string)$storedHash,$grantA['token']),
    'worker plaintext persisted');
$check($workerA->authenticate($grantA['token'])['job_id']===$jobA,'job scope mismatch');
$denied(fn()=>$workerB->authenticate($grantA['token']),
    'tenant_worker_capability_denied','worker cross-site secret replay');
$denied(fn()=>$workerA->authenticate('vwc_'.str_repeat('f',64)),
    'tenant_worker_capability_denied','unknown secret');
$denied(fn()=>$workerA->issue(1,$jobA,24),
    'tenant_worker_active_grant_or_schema_conflict','duplicate worker for same job');
$denied(fn()=>$workerA->issue(3,$otherJobA,24),
    'tenant_access_denied','author issuing worker capability');
$denied(fn()=>$workerA->issue(1,$otherJobA,0),
    'tenant_worker_grant_invalid','zero TTL');

$db->exec("UPDATE cms_site_memberships SET site_role='author' WHERE site_id=1 AND user_id=1");
$denied(fn()=>$workerA->authenticate($grantA['token']),
    'tenant_worker_capability_denied','worker after manager role downgrade');
$db->exec("UPDATE cms_site_memberships SET site_role='owner' WHERE site_id=1 AND user_id=1");
$db->exec("UPDATE cms_site_domains SET verified_at=NULL WHERE site_id=1");
$denied(fn()=>$workerA->authenticate($grantA['token']),
    'tenant_worker_capability_denied','worker on unverified site domain');
$db->exec("UPDATE cms_site_domains SET verified_at='2026-11-02 00:00:00' WHERE site_id=1");

$db->prepare("UPDATE cms_site_worker_credentials
    SET expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$grantA['id']]);
$denied(fn()=>$workerA->authenticate($grantA['token']),
    'tenant_worker_capability_denied','expired worker capability');
$db->prepare("UPDATE cms_site_worker_credentials SET expires_at='2099-01-01 00:00:00'
    WHERE id=?")->execute([$grantA['id']]);

$resultA=$workerA->runLocal($grantA['token']);
$check($resultA['status']==='succeeded' && $resultA['published']>=1
    && $resultA['external_delivery']===false,'worker local publication site A');
$otherStatus=$db->query("SELECT last_run_at FROM cms_site_local_jobs
    WHERE id=".$otherJobA)->fetchColumn();
$check($otherStatus===null,'worker ran another due job with same actor');
$check($db->query("SELECT status FROM cms_pages WHERE slug='worker-a'")->fetchColumn()
    ==='published','worker A draft not published');
$check($db->query("SELECT status FROM cms_pages WHERE slug='worker-b'")->fetchColumn()
    ==='draft','worker A published tenant B');
$check($workerA->runLocal($grantA['token'])['status']==='no_due_work',
    'worker A duplicate replay');

$resultB=$workerB->runLocal($grantB['token']);
$check($resultB['status']==='succeeded' && $resultB['published']>=1,
    'worker B local publication');
$workerA->revoke(1,(int)$grantA['id']);
$denied(fn()=>$workerA->authenticate($grantA['token']),
    'tenant_worker_capability_denied','revoked worker secret');
$rotated=$workerA->issue(1,$jobA,24);
$check(!hash_equals($rotated['token'],$grantA['token']),
    'worker rotation reused old secret');
$denied(fn()=>$workerB->revoke(1,(int)$grantB['id']),
    'tenant_access_denied','other tenant actor revoking worker');
$check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],
    'worker grant FK integrity');
echo "SITE_WORKER_CREDENTIALS=PASS one_site_one_job,short_lived,role_revocation,rotation,local_only\n";
$db->exec("INSERT INTO cms_pages(site_id,slug,locale,content_type,title,body,status,
    editorial_state,trash_status,published_at)
 VALUES(1,'worker-fenced','fa','post','Fenced task QA','Synthetic unpublished',
    'draft','scheduled','active','2000-01-01 00:00:00')");
$fenceJob=$qa->register(1,'worker-fence-revocation',60,'2000-01-01 00:00:00',true);
$fenceCapability=$workerA->issue(1,$fenceJob,2);
$identity=$workerA->authenticate($fenceCapability['token']);
$leaseRunner=new VazinCMS\SiteScopedJobLeaseRunner($db,1);
$claim=$leaseRunner->claimNext(1,$identity['job_id']);
$check(is_array($claim)&&$claim['id']===$fenceJob,'worker failed bound claim');
$workerA->revoke(1,(int)$fenceCapability['id']);
$result=$leaseRunner->executeClaim(
    $identity['actor_id'],$claim,
    fn()=>$workerA->authenticate($fenceCapability['token'])
);
$check($result['status']==='review_required' && $result['operator_review_required'],
    'revoked worker token failed to quarantine in page transaction');
$check($db->query("SELECT status FROM cms_pages WHERE slug='worker-fenced'")
    ->fetchColumn()==='draft','revoked token published content');
$check($db->query("SELECT is_active FROM cms_site_local_jobs WHERE id=".$fenceJob)
    ->fetchColumn()==0,'revoked token left job active');
echo "SITE_WORKER_TRANSACTION_FENCE=PASS revoked_during_claim_no_publish,manual_review_required\n";


