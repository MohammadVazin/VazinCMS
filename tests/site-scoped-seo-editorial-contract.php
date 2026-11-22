<?php
declare(strict_types=1);

$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void {
    $prefix='VazinCMS\\';
    if(!str_starts_with($name,$prefix))return;
    $path=$root.'/src/'.str_replace('\\','/',substr($name,strlen($prefix))).'.php';
    if(is_file($path))require $path;
});
use VazinCMS\{SiteScopedSeoService,SiteScopedEditorialWorkflow,SiteScopeMode};

$check=static function(bool $condition,string $why):void {
    if(!$condition)throw new RuntimeException('SCOPED_SEO_EDITORIAL_FAIL: '.$why);
};
$denied=static function(callable $action,string $expected,string $label)use($check):void {
    try{$action();}catch(RuntimeException $error){
        $check($error->getMessage()===$expected,
            $label.' expected '.$expected.' observed '.$error->getMessage());
        return;
    }
    throw new RuntimeException('SCOPED_SEO_EDITORIAL_UNEXPECTED_SUCCESS: '.$label);
};

$pdo=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,role TEXT,status TEXT);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,
 PRIMARY KEY(site_id,user_id));
CREATE TABLE cms_pages(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 slug TEXT NOT NULL,locale TEXT NOT NULL DEFAULT 'fa',
 title TEXT NOT NULL,body TEXT NOT NULL DEFAULT '',
 meta_title TEXT NOT NULL DEFAULT '',meta_description TEXT NOT NULL DEFAULT '',
 canonical_url TEXT NOT NULL DEFAULT '',featured_image TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'draft',trash_status TEXT NOT NULL DEFAULT 'active',
 content_type TEXT NOT NULL DEFAULT 'post',author_id INTEGER,
 editorial_state TEXT NOT NULL DEFAULT 'draft',published_at TEXT,
 review_requested_at TEXT,reviewed_at TEXT,reviewed_by INTEGER,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,slug,locale),UNIQUE(site_id,id)
);
CREATE TABLE cms_settings(
 site_id INTEGER NOT NULL,setting_key TEXT NOT NULL,setting_value TEXT NOT NULL,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(site_id,setting_key)
);
CREATE TABLE cms_search_index(
 page_id INTEGER PRIMARY KEY,site_id INTEGER NOT NULL,locale TEXT,
 content_type TEXT,title TEXT,search_text TEXT,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE cms_site_seo_reports(
 site_id INTEGER NOT NULL,page_id INTEGER NOT NULL,score INTEGER NOT NULL,
 content_hash TEXT,issues_json TEXT,analyzed_at TEXT DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(site_id,page_id),
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id)
);
CREATE TABLE cms_site_content_advisories(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,page_id INTEGER NOT NULL,
 rule_key TEXT,severity TEXT,category TEXT,title TEXT,message TEXT,
 details_json TEXT,status TEXT DEFAULT 'open',fingerprint TEXT,
 first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,
 resolved_at TEXT,UNIQUE(site_id,page_id,rule_key),
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id)
);
CREATE TABLE cms_site_editorial_suggestions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,suggestion_key TEXT,title TEXT,
 rationale TEXT,suggested_at TEXT,status TEXT DEFAULT 'open',details_json TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,suggestion_key)
);
CREATE TABLE cms_site_editorial_events(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,page_id INTEGER NOT NULL,
 from_state TEXT,to_state TEXT,actor_id INTEGER,note TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id)
);
INSERT INTO cms_sites VALUES(1,'alpha','active'),(2,'beta','active'),(3,'disabled','suspended');
INSERT INTO users VALUES(1,'A manager','owner','active'),(2,'B manager','admin','active'),
 (3,'A author','client','active'),(4,'B disabled','admin','suspended');
INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(2,2,'admin'),
 (1,3,'author'),(2,4,'admin');
INSERT INTO cms_pages(site_id,slug,locale,title,body,meta_description,editorial_state)
 VALUES(1,'shared','fa','Alpha verified article',
 'A paragraph with a medical absolute claim cure all',
 'Alpha SEO description for analysis','draft');
INSERT INTO cms_pages(site_id,slug,locale,title,body,meta_description,editorial_state)
 VALUES(2,'shared','fa','Beta private article',
 'B paragraph about technology only', '', 'draft');
SQL);
foreach([
'cms_media','cms_menu_items','cms_redirects','cms_taxonomies','cms_terms',
'cms_term_relationships','cms_page_revisions','cms_autosaves',
'cms_media_collections','cms_media_derivatives','cms_media_collection_items',
'cms_content_types','cms_forms','cms_form_fields','cms_form_submissions',
'cms_reusable_blocks','cms_block_patterns','cms_editorial_events',
'publication_jobs','cms_custom_field_definitions','cms_custom_field_values'
] as $table) {
    $pdo->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
}
$check(SiteScopeMode::current($pdo)==='scoped','24-table schema not detected');

$a=new SiteScopedSeoService($pdo,1);
$b=new SiteScopedSeoService($pdo,2);
$denied(static fn()=>new SiteScopedSeoService($pdo,3),
    'tenant_site_unavailable','suspended site');
$denied(static fn()=>$b->analyzePage(1,2),
    'tenant_access_denied','A manager no membership B');
$denied(static fn()=>$a->analyzePage(3,1),
    'tenant_access_denied','author disallowed');
$denied(static fn()=>$b->analyzePage(4,2),
    'tenant_access_denied','inactive B admin');
$denied(static fn()=>$a->analyzePage(1,2),
    'tenant_content_unavailable','read B by page id in A service');

$aReport=$a->analyzePage(1,1);
$bReport=$b->analyzePage(2,2);
$check(isset($aReport['score'],$bReport['score'])&&$aReport['score']<100,
       'scored reports missing');
$check((int)$pdo->query('SELECT COUNT(*) FROM cms_site_seo_reports')->fetchColumn()===2,
       'two site reports not stored');
$advisoryA=$a->dashboard(1)['advisories'];
$advisoryB=$b->dashboard(2)['advisories'];
$check(count($advisoryA)>0 && count($advisoryB)>0,'site advisories not generated');
$check(!str_contains(json_encode($advisoryA),'Beta private'),'beta content appeared A');
$check(!str_contains(json_encode($advisoryB),'Alpha verified'),'alpha content appeared B');
$check($a->dashboard(1)['summary']['pages']===1,'A content count incorrect');
$check($b->dashboard(2)['summary']['pages']===1,'B content count incorrect');

$advisoryIdB=(int)$advisoryB[0]['id'];
$denied(static fn()=>$a->setAdvisoryStatus(1,$advisoryIdB,'ignored'),
    'tenant_content_unavailable','A changing B advisory');
$advisoryIdA=(int)$advisoryA[0]['id'];
$a->setAdvisoryStatus(1,$advisoryIdA,'acknowledged');
$after=$a->analyzePage(1,1);
$check($after['new_notices']===0,'repeat analysis created notices');
$status=$pdo->query('SELECT status FROM cms_site_content_advisories WHERE id='.$advisoryIdA)->fetchColumn();
$check($status==='acknowledged','unchanged fingerprint reset review status');
$check((int)$pdo->query('SELECT COUNT(*) FROM cms_site_seo_reports')->fetchColumn()===2,
       'repeat report generated duplicate');
echo "SITE_SEO_ANALYSIS=PASS two_site_counts,roles,advisories,replay,no_cross_site_status\n";

$a->saveCadence(1,5);
$b->saveCadence(2,2);
$check($a->cadence()===5 && $b->cadence()===2,'cadence leaked sites');
$denied(static fn()=>$b->saveCadence(1,15),'tenant_access_denied','A changing B cadence');
$aSuggestion=$a->propose(1);
$bSuggestion=$b->propose(2);
$check(isset($aSuggestion['suggestion_key'],$bSuggestion['suggestion_key']),
       'suggestion keys absent');
$check((int)$pdo->query('SELECT COUNT(*) FROM cms_site_editorial_suggestions')->fetchColumn()===2,
       'suggestion per-site uniqueness failed');
$aSuggestions=$a->dashboard(1)['suggestions'];
$bSuggestions=$b->dashboard(2)['suggestions'];
$check(count($aSuggestions)===1&&count($bSuggestions)===1,'suggestions missing');
$denied(static fn()=>$a->setSuggestionStatus(1,(int)$bSuggestions[0]['id'],'dismissed'),
    'tenant_content_unavailable','cross-site suggestion update');
$a->setSuggestionStatus(1,(int)$aSuggestions[0]['id'],'accepted');
$a->propose(1);
$check($a->dashboard(1)['suggestions'][0]['status']==='accepted',
       'refresh suggestion lost managerial decision');
$check($b->dashboard(2)['suggestions'][0]['status']==='open',
       'site A decision updated B suggestion');
echo "SITE_EDITORIAL_CADENCE=PASS separate_settings,suggestions,decision_preserved\n";

$aWorkflow=new SiteScopedEditorialWorkflow($pdo,1);
$bWorkflow=new SiteScopedEditorialWorkflow($pdo,2);
$denied(static fn()=>$bWorkflow->transition(1,2,'in_review'),
    'tenant_access_denied','A actor B workflow');
$denied(static fn()=>$aWorkflow->transition(1,2,'in_review'),
    'tenant_content_unavailable','B page A workflow');
$denied(static fn()=>$aWorkflow->transition(3,1,'in_review'),
    'tenant_access_denied','author without editorial permission');
$denied(static fn()=>$aWorkflow->transition(1,1,'scheduled'),
    'tenant_editorial_transition_denied','skip review');
$aWorkflow->transition(1,1,'in_review','QA review requested');
$aWorkflow->transition(1,1,'approved','QA review completed');
$denied(static fn()=>$aWorkflow->transition(1,1,'scheduled'),
    'tenant_editorial_schedule_invalid','missing valid future schedule');

$pdo->exec("UPDATE cms_pages SET published_at=datetime('now','+2 days') WHERE id=1");
$aWorkflow->transition(1,1,'scheduled','scheduled in future');
$check($aWorkflow->due(1)===[],'future content prematurely due');
$check($aWorkflow->publishDue(1)['published']===0,'future content published');
$check($pdo->query('SELECT status FROM cms_pages WHERE id=1')->fetchColumn()==='draft',
       'scheduled content went public');

$pdo->exec("UPDATE cms_pages SET published_at=datetime('now','-1 hour') WHERE id=1");
$check($aWorkflow->due(1)===[1],'due selection failed');
$check($bWorkflow->due(2)===[],'B workflow got A due page');
$published=$aWorkflow->publishDue(1);
$check($published['published']===1&&$published['external_delivery']===false,
       'local publication failed');
$check($aWorkflow->publishDue(1)['published']===0,'local publish replay not idempotent');
$check($pdo->query('SELECT status FROM cms_pages WHERE id=1')->fetchColumn()==='published',
       'page status did not change to published');
$check($pdo->query('SELECT status FROM cms_pages WHERE id=2')->fetchColumn()==='draft',
       'B page changed by A publisher');
$check((int)$pdo->query('SELECT COUNT(*) FROM cms_search_index WHERE site_id=1 AND page_id=1')->fetchColumn()===1,
       'published page missing search index');
$check((int)$pdo->query('SELECT COUNT(*) FROM cms_search_index WHERE site_id=2')->fetchColumn()===0,
       'B search index modified');
$events=$aWorkflow->events(1,1);
$check(count($events)===4,'audit trail incomplete (review approve schedule publish)');
$denied(static fn()=>$aWorkflow->events(1,2),
    'tenant_content_unavailable','A reading B workflow log');
$check($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'site data FK check');
echo "SITE_EDITORIAL_WORKFLOW=PASS review,approve,schedule,future_denied,due,local_publish,index,events\n";
echo "SITE_SEO_EDITORIAL_CONTRACT=PASS test_only=true external_delivery=false\n";
