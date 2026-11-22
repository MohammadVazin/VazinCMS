<?php
declare(strict_types=1);
putenv('SESSION_SECURE=false');
require dirname(__DIR__).'/src/bootstrap.php';

use VazinCMS\{BlockRepository,SiteScopedBlockRepository,SiteContext,SiteScopeMode};
$assert=static function(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('TENANT_BLOCK_TEST_FAIL: '.$reason);
};
$refuse=static function(callable $fn,string $reason)use($assert):void{
    try{$fn();}catch(RuntimeException $e){
        $assert($e->getMessage()===$reason,'wrong denial '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Expected denial '.$reason);
};
$pdo=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);");
$pdo->exec("CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,verified_at TEXT,is_primary INTEGER DEFAULT 1);");
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);");
$pdo->exec("CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,PRIMARY KEY(site_id,user_id));");
$pdo->exec("CREATE TABLE cms_reusable_blocks(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 block_key TEXT NOT NULL,label TEXT NOT NULL,block_document TEXT NOT NULL,
 block_schema_version INTEGER DEFAULT 1,created_by INTEGER,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,block_key));");
$pdo->exec("CREATE TABLE cms_block_patterns(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 pattern_key TEXT NOT NULL,label TEXT NOT NULL,category TEXT DEFAULT 'layout',
 block_document TEXT NOT NULL,block_schema_version INTEGER DEFAULT 1,
 is_system INTEGER DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(site_id,pattern_key));");
foreach([
'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
'cms_autosaves','cms_search_index','cms_media_collections',
'cms_media_derivatives','cms_media_collection_items','cms_content_types',
'cms_forms','cms_form_fields','cms_form_submissions',
'cms_editorial_events','publication_jobs','cms_custom_field_definitions',
'cms_custom_field_values'
] as $table){
    $pdo->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
}
$assert(SiteScopeMode::current($pdo)==='scoped','full schema');
$pdo->exec("INSERT INTO cms_sites VALUES(1,'default','active'),(2,'beta','active');");
$pdo->exec("INSERT INTO cms_site_domains VALUES(1,'a.example.test','2026-11-01 00:00:00',1),
(2,'b.example.test','2026-11-01 00:00:00',1);");
$pdo->exec("INSERT INTO users VALUES(1,'owner','active'),(2,'admin','active'),
(3,'client','active'),(4,'admin','suspended');");
$pdo->exec("INSERT INTO cms_site_memberships VALUES(1,1,'owner'),(2,2,'admin'),
(1,3,'author'),(2,4,'admin');");

$first=new SiteScopedBlockRepository($pdo,1);
$second=new SiteScopedBlockRepository($pdo,2);
$docA=VazinCMS\BlockEditor::legacy('Alpha-only copy');
$docB=VazinCMS\BlockEditor::legacy('Beta-only copy');

$idA=$first->saveReusable(1,'same-block','Alpha block',$docA);
$idB=$second->saveReusable(2,'same-block','Beta block',$docB);
$assert($idA!==$idB,'cross site block ids');
$assert(str_contains((string)$first->reusable('same-block')['block_document'],'Alpha-only'),
        'site A block lost');
$assert(str_contains((string)$second->reusable('same-block')['block_document'],'Beta-only'),
        'site B block lost');
$updated=$first->saveReusable(1,'same-block','Alpha renamed',VazinCMS\BlockEditor::legacy('Alpha-v2'));
$assert($updated===$idA,'A block update idempotency');
$assert($second->reusable('same-block')['label']==='Beta block','A update modified B');
$refuse(static fn()=>$second->saveReusable(1,'another-block','Cross-site mutation',$docA),
    'tenant_access_denied');
$refuse(static fn()=>$first->saveReusable(3,'author-block','Wrong role',$docA),
    'tenant_access_denied');
$refuse(static fn()=>$second->saveReusable(4,'inactive-block','Suspended',$docA),
    'tenant_access_denied');

$patterns=[
    ['hero-basic','Hero block','layout',VazinCMS\BlockEditor::legacy('Hero')],
    ['article-intro','Article intro','content',VazinCMS\BlockEditor::legacy('Intro')]
];
$assert($first->seedPatterns($patterns)===2,'seed A');
$assert($second->seedPatterns($patterns)===2,'seed B');
$assert($first->seedPatterns($patterns)===0,'repeat seed idempotent');
$assert(count($first->patterns())===2 && count($second->patterns())===2,'pattern lists scoped');
$assert(count($pdo->query("SELECT id FROM cms_block_patterns WHERE pattern_key='hero-basic'")->fetchAll())===2,
    'cross-site pattern duplicate not supported');

SiteContext::reset();SiteContext::resolve($pdo,'a.example.test');
$saveId=BlockRepository::saveReusable($pdo,'same-block','Alpha via facade',VazinCMS\BlockEditor::legacy('Facade A'),1);
$assert($saveId===$idA,'BlockRepository not site-scoped');
$assert($second->reusable('same-block')['label']==='Beta block','facade A changed B');
$assert(count(BlockRepository::patterns($pdo))===2,'facade patterns A');
SiteContext::reset();SiteContext::resolve($pdo,'b.example.test');
$assert(count(BlockRepository::patterns($pdo))===2,'facade patterns B');
$assert($first->reusable('same-block')['label']==='Alpha via facade','B leaked A');
$assert($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'FK integrity');
SiteContext::reset();

$legacy=new PDO('sqlite::memory:',null,null,[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$legacy->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);');
$legacy->exec("INSERT INTO cms_sites VALUES(1,'default','active');");
$legacy->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);');
$legacy->exec("INSERT INTO users VALUES(1,'owner','active');");
$legacy->exec('CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT);');
$legacy->exec("INSERT INTO cms_site_memberships VALUES(1,1,'owner');");
$legacy->exec('CREATE TABLE cms_reusable_blocks(id INTEGER PRIMARY KEY,site_id INTEGER,block_key TEXT UNIQUE);');
$legacy->exec('CREATE TABLE cms_block_patterns(id INTEGER PRIMARY KEY,site_id INTEGER,pattern_key TEXT UNIQUE);');
foreach([
'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
'cms_autosaves','cms_search_index','cms_media_collections',
'cms_media_derivatives','cms_media_collection_items','cms_content_types',
'cms_forms','cms_form_fields','cms_form_submissions',
'cms_editorial_events','publication_jobs','cms_custom_field_definitions',
'cms_custom_field_values'
]as$table)$legacy->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
$refuse(static fn()=>(new SiteScopedBlockRepository($legacy,1))->saveReusable(
 1,'same-block','No composite constraint',$docA),'tenant_block_uniqueness_not_ready');
echo "SITE_SCOPED_BLOCKS=PASS two_sites_roles,save,update,patterns,idempotent,legacy_denied\n";
