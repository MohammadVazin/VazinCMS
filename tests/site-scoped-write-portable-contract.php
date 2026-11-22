<?php
declare(strict_types=1);
// Standalone, portable, in-memory-only test. No runtime credentials or files.
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void{
    $prefix='VazinCMS\\';
    if(!str_starts_with($name,$prefix))return;
    $file=$root.'/src/'.str_replace('\\','/',substr($name,strlen($prefix))).'.php';
    if(is_file($file))require $file;
});
use VazinCMS\SiteScopedContentWriter;
use VazinCMS\SiteScopeMode;
use VazinCMS\SiteScopedContentRepository;

function checkWrite(bool $value,string $reason):void{
    if(!$value)throw new RuntimeException('SITE_SCOPED_WRITE_CONTRACT_FAIL: '.$reason);
}
function mustRefuse(callable $action,string $message):void{
    try{$action();}catch(RuntimeException $error){
        checkWrite($error->getMessage()===$message,'Wrong refusal: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected refusal: '.$message);
}
$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,
 PRIMARY KEY(site_id,user_id));
CREATE TABLE cms_pages(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 slug TEXT NOT NULL,locale TEXT NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'draft',is_home INTEGER NOT NULL DEFAULT 0,
 author_id INTEGER,meta_title TEXT DEFAULT '',meta_description TEXT DEFAULT '',
 canonical_url TEXT DEFAULT '',featured_image TEXT DEFAULT '',content_type TEXT DEFAULT 'page',
 published_at TEXT,robots_index INTEGER DEFAULT 1,robots_follow INTEGER DEFAULT 1,
 og_title TEXT DEFAULT '',og_description TEXT DEFAULT '',schema_type TEXT DEFAULT 'WebPage',
 block_schema_version INTEGER DEFAULT 0,block_document TEXT DEFAULT '',
 trash_status TEXT DEFAULT 'active',trashed_at TEXT,trashed_by INTEGER,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(slug,locale)
);
CREATE TABLE cms_page_revisions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,page_id INTEGER,title TEXT,body TEXT,
 meta_title TEXT,meta_description TEXT,created_by INTEGER,
 revision_kind TEXT,content_hash TEXT,metadata_json TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE cms_autosaves(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,page_id INTEGER,actor_id INTEGER,
 content_hash TEXT,title TEXT,body TEXT,metadata_json TEXT,
 saved_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(page_id,actor_id)
);
CREATE TABLE cms_media(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,file_name TEXT,stored_name TEXT,
 mime_type TEXT,alt_text TEXT,uploaded_by INTEGER,sha256 TEXT);
CREATE TABLE cms_media_derivatives(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,media_id INTEGER,stored_name TEXT,
 width INTEGER);
CREATE TABLE cms_menu_items(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,locale TEXT,label TEXT,url TEXT,
 position INTEGER,is_enabled INTEGER
);
CREATE TABLE cms_settings(
 setting_key TEXT PRIMARY KEY,site_id INTEGER,setting_value TEXT,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO cms_sites VALUES(1,'a','active'),(2,'b','active');
INSERT INTO users VALUES(1,'owner','active'),(2,'owner','active'),
 (3,'client','active'),(4,'support','active'),(5,'owner','suspended');
INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(2,2,'owner'),
 (1,3,'author'),(1,4,'support'),(2,5,'admin');
SQL);
foreach([
    'cms_redirects','cms_taxonomies','cms_terms','cms_term_relationships',
    'cms_search_index','cms_media_collections','cms_media_collection_items',
    'cms_content_types','cms_forms','cms_form_fields','cms_form_submissions',
    'cms_reusable_blocks','cms_block_patterns','cms_editorial_events',
    'publication_jobs','cms_custom_field_definitions','cms_custom_field_values'
]as $table){
    $db->exec('CREATE TABLE '.$table.'(id INTEGER PRIMARY KEY,site_id INTEGER)');
}
checkWrite(SiteScopeMode::current($db)==='scoped','full scoped schema');
$a=new SiteScopedContentWriter($db,1);
$b=new SiteScopedContentWriter($db,2);
$repo=new SiteScopedContentRepository($db,1);
$record=[
    'slug'=>'post-a','locale'=>'fa','title'=>'Original Alpha','body'=>'first',
    'status'=>'draft','content_type'=>'page','schema_type'=>'WebPage','home'=>1
];
$page=$a->savePage(1,0,$record);
checkWrite($page>0,'insert page');
checkWrite((int)$db->query('SELECT site_id FROM cms_pages WHERE id='.$page)->fetchColumn()===1,'site ownership');
checkWrite(count($a->pages(1))===1,'site list A');
checkWrite(count($b->pages(2))===0,'site list B empty');
mustRefuse(fn()=>$b->savePage(1,0,$record),'tenant_access_denied');
mustRefuse(fn()=>$b->page(2,$page),'tenant_content_unavailable');
mustRefuse(fn()=>$b->trash(2,$page),'tenant_content_unavailable');
mustRefuse(fn()=>$a->trash(4,$page),'tenant_access_denied');
$record['title']='Updated Alpha';$record['status']='published';
$a->savePage(1,$page,$record);
checkWrite($repo->publicPage('fa','post-a')['title']==='Updated Alpha','public updated');
$revision=(int)$db->query('SELECT id FROM cms_page_revisions ORDER BY id DESC LIMIT 1')->fetchColumn();
checkWrite($revision>0,'revision created');
mustRefuse(fn()=>$b->restoreRevision(2,$revision),'tenant_content_unavailable');
$a->restoreRevision(1,$revision);
checkWrite($repo->publicPage('fa','post-a')['title']==='Original Alpha','revision restore');
$as=$a->autosave(1,$page,'Draft','new body');
checkWrite($as['saved']===true && strlen($as['hash'])===64,'autosave');
$a->autosave(1,$page,'Second Draft','second body');
checkWrite((int)$db->query('SELECT COUNT(*) FROM cms_autosaves')->fetchColumn()===1,'autosave idempotent');
mustRefuse(fn()=>$b->autosave(2,$page,'Cross','Cross'),'tenant_content_unavailable');

$a->trash(1,$page);
checkWrite($repo->publicPage('fa','post-a')===null,'trash hides public');
$a->restoreTrash(1,$page);
checkWrite($repo->publicPage('fa','post-a')!==null,'restore shows public');
mustRefuse(fn()=>$b->restoreTrash(2,$page),'tenant_content_unavailable');

$authorPage=$a->savePage(3,0,[
    'slug'=>'author-page','locale'=>'fa','title'=>'Author Alpha',
    'body'=>'author body','content_type'=>'page','status'=>'draft'
]);
checkWrite((int)$a->page(3,$authorPage)['author_id']===3,'author ownership');
mustRefuse(fn()=>$a->savePage(3,$page,$record),'tenant_content_unavailable');

$menu=$a->saveMenu(1,'fa','A menu','/fa/page/post-a');
checkWrite($menu>0,'menu insert');
mustRefuse(fn()=>$b->deleteMenu(2,$menu),'tenant_content_unavailable');
mustRefuse(fn()=>$a->saveMenu(3,'fa','Author menu','/invalid'),'tenant_access_denied');
$a->deleteMenu(1,$menu);
checkWrite((int)$db->query('SELECT COUNT(*) FROM cms_menu_items')->fetchColumn()===0,'menu delete');

$a->saveSettings(1,['site_name'=>'Alpha site','site_tagline'=>'Safe']);
checkWrite($repo->settingsMap()['site_name']==='Alpha site','settings saved');
mustRefuse(fn()=>$b->saveSettings(2,['site_name'=>'Overwrite A']),'tenant_setting_conflict');
checkWrite($repo->settingsMap()['site_name']==='Alpha site','settings conflict no mutation');
mustRefuse(fn()=>$a->saveSettings(3,['site_name'=>'Not owner']),'tenant_access_denied');

$db->exec("INSERT INTO cms_media(site_id,file_name,stored_name,mime_type,alt_text)
 VALUES(1,'alpha.png','11111111111111111111111111111111.png','image/png','QA Alpha')");
$id=(int)$db->lastInsertId();
checkWrite($a->mediaRow(1,$id)['site_id']==1,'media owner');
mustRefuse(fn()=>$b->mediaRow(2,$id),'tenant_content_unavailable');
checkWrite(!str_contains(json_encode($b->media(2)),'QA Alpha'),'media isolation');
checkWrite((int)$db->query('SELECT COUNT(*) FROM cms_pages WHERE site_id=1')->fetchColumn()===2,
    'site B did not mutate A');
echo "SITE_SCOPED_WRITE_PORTABLE=PASS two_site_roles,save,edit,versions,autosave,trash,menu,settings,media\n";
