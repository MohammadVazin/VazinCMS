<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/SiteUniqueKeys.php';
require dirname(__DIR__) . '/src/SiteScopedContentRepository.php';
use VazinCMS\SiteScopedContentRepository;

$assert = static function(bool $condition,string $label):void {
    if (!$condition) throw new RuntimeException('TENANT_REPOSITORY_FAIL: '.$label);
};
$denied = static function(callable $fn,string $expected,string $label):void {
    try {$fn();} catch(RuntimeException $error) {
        if ($error->getMessage()===$expected) return;
        throw new RuntimeException($label.': wrong error '.$error->getMessage());
    }
    throw new RuntimeException($label.': unexpected success');
};

$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,role TEXT,status TEXT);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,PRIMARY KEY(site_id,user_id));
CREATE TABLE cms_pages(
  id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL REFERENCES cms_sites(id),
  slug TEXT NOT NULL,locale TEXT,title TEXT,body TEXT,status TEXT,is_home INTEGER,
  trash_status TEXT NOT NULL DEFAULT 'active',author_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,trashed_at TEXT,trashed_by INTEGER,
  UNIQUE(slug,locale)
);
CREATE TABLE cms_media(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL REFERENCES cms_sites(id),
  file_name TEXT,mime_type TEXT,alt_text TEXT,stored_name TEXT);
CREATE TABLE cms_menu_items(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL REFERENCES cms_sites(id),
  label TEXT,url TEXT,locale TEXT,is_enabled INTEGER,position INTEGER);
CREATE TABLE cms_page_revisions(id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL REFERENCES cms_sites(id),page_id INTEGER,title TEXT,body TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE cms_search_index(site_id INTEGER NOT NULL REFERENCES cms_sites(id),
  page_id INTEGER,locale TEXT,content_type TEXT,title TEXT,search_text TEXT);
CREATE TABLE cms_redirects(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL REFERENCES cms_sites(id),
  source_path TEXT UNIQUE,target_url TEXT,status_code INTEGER,created_by INTEGER,hits INTEGER NOT NULL DEFAULT 0,last_hit_at TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO cms_sites VALUES(1,'site-a','active'),(2,'site-b','active'),(3,'site-disabled','suspended');
INSERT INTO users VALUES(1,'A owner','owner','active'),(2,'B owner','owner','active'),
(3,'A support','support','active'),(4,'A author','client','active'),
(5,'A editor','client','active'),(6,'B former','owner','suspended');
INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(2,2,'owner'),(1,3,'support'),
(1,4,'author'),(1,5,'editor'),(2,6,'admin');
SQL);

$a=new SiteScopedContentRepository($db,1);
$b=new SiteScopedContentRepository($db,2);
$denied(static fn()=>new SiteScopedContentRepository($db,3),'tenant_site_unavailable','suspended site');
$denied(static fn()=>new SiteScopedContentRepository($db,999),'tenant_site_unavailable','nonexistent site');

$ownPage=$a->createDraft(1,'fa','site-a-only','A page','A unique content');
$betaPage=$b->createDraft(2,'fa','site-b-only','B page','DO_NOT_LEAK_B');
$authorPage=$a->createDraft(4,'fa','author-page','My page','my own draft');
$denied(static fn()=>$a->createDraft(3,'fa','support-denied','No access','x'),'tenant_access_denied','support must not author');
$denied(static fn()=>$b->createDraft(1,'fa','global-owner-denied','No access','x'),'tenant_access_denied','global owner without site membership');
$denied(static fn()=>$b->createDraft(6,'fa','suspended-denied','No access','x'),'tenant_access_denied','suspended member');
$denied(static fn()=>$a->updateBody(4,$ownPage,'Hacked title','Hacked'),'tenant_content_unavailable','author cross-owner edit');
$denied(static fn()=>$a->updateBody(1,$betaPage,'Hacked title','Hacked'),'tenant_content_unavailable','cross-tenant page update');
$denied(static fn()=>$a->revisions(1,$betaPage),'tenant_content_unavailable','cross-tenant revisions');
$denied(static fn()=>$a->trashPage(1,$betaPage),'tenant_content_unavailable','cross-tenant trash');
$denied(static fn()=>$b->listEditablePages(1),'tenant_access_denied','cross-tenant listing');

$assert($a->updateBody(5,$ownPage,'A updated','A updated body'),'editor can update own site');
$assert($a->updateBody(4,$authorPage,'Author updated','Own body'),'author can update authored content');
$authorListing=$a->listEditablePages(4);
$assert(count($authorListing)===1 && (int)$authorListing[0]['id']===$authorPage,'author list filtered');
$pagesA=$a->listEditablePages(1);
$pagesB=$b->listEditablePages(2);
$assert(count($pagesA)===2 && count($pagesB)===1,'list count scoped');
$assert(!str_contains(json_encode($pagesA), 'site-b-only'),'page list leaks site B slug');

$db->prepare("UPDATE cms_pages SET status='published' WHERE id=?")->execute([$ownPage]);
$db->prepare("UPDATE cms_pages SET status='published' WHERE id=?")->execute([$betaPage]);
$assert($a->publicPage('fa','site-b-only')===null,'public page from B leaked to A');
$assert($a->publicPage('fa','site-a-only')['title']==='A updated','public page on A missing');
$assert($b->publicPage('fa','site-a-only')===null,'public A leaked to B');
$assert($a->publicPage('fa','author-page')===null,'draft leaked publicly');

$db->exec("INSERT INTO cms_media(site_id,file_name,mime_type,alt_text,stored_name) VALUES
(1,'a.png','image/png','A image','A-SENTINEL.png'),
(2,'b.png','image/png','B image','B-SENTINEL.png')");
$mediaB=(int)$db->query("SELECT id FROM cms_media WHERE site_id=2")->fetchColumn();
$assert(count($a->mediaList())===1 && count($b->mediaList())===1,'media listing filtered');
$assert($a->mediaById($mediaB)===null,'media B accessed by A');
$assert(!str_contains(json_encode($a->mediaList()),'B-SENTINEL'),'media B exposed in A list');

$db->exec("INSERT INTO cms_menu_items(site_id,label,url,locale,is_enabled,position) VALUES
(1,'A menu','/a','fa',1,1),(2,'B menu','/b','fa',1,1)");
$assert(count($a->menuItems('fa'))===1 && $a->menuItems('fa')[0]['label']==='A menu','menu B leaked into A');

$db->prepare("INSERT INTO cms_page_revisions(site_id,page_id,title,body) VALUES (1,?,'A revision','A rev body')")->execute([$ownPage]);
$db->prepare("INSERT INTO cms_page_revisions(site_id,page_id,title,body) VALUES (2,?,'B revision','B rev body')")->execute([$betaPage]);
$assert(count($a->revisions(1,$ownPage))===1 && $a->revisions(1,$ownPage)[0]['title']==='A revision','revision B leak');

$db->prepare("INSERT INTO cms_search_index(site_id,page_id,locale,content_type,title,search_text) VALUES (1,?,'fa','page','A Title','unique match a')")->execute([$ownPage]);
$db->prepare("INSERT INTO cms_search_index(site_id,page_id,locale,content_type,title,search_text) VALUES (2,?,'fa','page','B Title','unique match b')")->execute([$betaPage]);
$assert(count($a->search('unique match b'))===0,'B search result leaked to A');
$assert(count($b->search('unique match a'))===0,'A search result leaked to B');
$assert(count($b->search('unique match b'))===1,'B search result missing');

$a->saveRedirect(1,'/a-redirect','/a',301);
$b->saveRedirect(2,'/b-redirect','/b',302);
$assert($a->redirect('/a-redirect')['target_url']==='/a','A redirect missing');
$assert($b->redirect('/b-redirect')['target_url']==='/b','B redirect missing');
$assert($a->redirect('/b-redirect')===null,'A leaked B redirect');
$assert($b->redirect('/a-redirect')===null,'B leaked A redirect');
$denied(static fn()=>$b->saveRedirect(2,'/a-redirect','/takeover',301),
    'tenant_redirect_conflict','cross-tenant redirect takeover');
$denied(static fn()=>$a->saveRedirect(3,'/support-redirect','/a',301),
    'tenant_access_denied','support cannot create redirect');
$a->saveRedirect(1,'/a-redirect','/a-updated',308);
$assert($a->redirect('/a-redirect')['target_url']==='/a-updated','own redirect update failed');
$assert($a->redirect('/b-redirect')===null,'B redirect became visible to A');
$assert((int)$db->query("SELECT COUNT(*) FROM cms_redirects")->fetchColumn()===2,'redirect conflict created new row');

$a->trashPage(1,$ownPage);
$assert($a->publicPage('fa','site-a-only')===null,'trashed page remains public');
$assert(count($a->search('unique match a'))===0,'trashed page remains searchable');

$legacy=new PDO('sqlite::memory:');
$legacy->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY)');
$denied(static fn()=>new SiteScopedContentRepository($legacy,1),
    'tenant_schema_unavailable','unmigrated schema should never fall back to global data');
echo "SITE_SCOPED_CONTENT_REPOSITORY=PASS two_site_pages_roles_media_revisions_search_redirects_trash\n";
