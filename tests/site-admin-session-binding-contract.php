<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void {
    $prefix='VazinCMS\\';
    if(!str_starts_with($name,$prefix))return;
    $file=$root.'/src/'.str_replace('\\','/',substr($name,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
use VazinCMS\{SiteScopedAdminSessionBinding,SiteScopeMode};

$check=static function(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SITE_SESSION_BIND_FAIL: '.$why);
};
$deny=static function(callable $callback,string $expected)use($check):void {
    try{$callback();}catch(RuntimeException $e){
        $check($e->getMessage()===$expected,'wrong failure: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('SITE_SESSION_BIND_UNEXPECTED_SUCCESS: '.$expected);
};
$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,status TEXT,session_version INTEGER);
CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,verified_at TEXT);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,
 PRIMARY KEY(site_id,user_id));
CREATE TABLE active_sessions(
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
 session_hash TEXT NOT NULL UNIQUE,revoked_at TEXT);
CREATE TABLE cms_site_admin_sessions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id),
 user_id INTEGER NOT NULL REFERENCES users(id),
 active_session_id INTEGER NOT NULL UNIQUE REFERENCES active_sessions(id),
 session_hash TEXT NOT NULL UNIQUE,
 bound_host TEXT NOT NULL,auth_provider TEXT NOT NULL,
 session_version INTEGER NOT NULL,
 authenticated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 expires_at TEXT NOT NULL,revoked_at TEXT,
 FOREIGN KEY(site_id,user_id) REFERENCES cms_site_memberships(site_id,user_id)
);
INSERT INTO cms_sites VALUES(1,'default','active'),(2,'beta','active');
INSERT INTO users VALUES(1,'A owner','active',1),(2,'B support','active',2),
 (3,'Suspended','suspended',1);
INSERT INTO cms_site_domains VALUES
 (1,'alpha.example.test','2026-11-03 00:00:00'),
 (2,'beta.example.test','2026-11-03 00:00:00');
INSERT INTO cms_site_memberships VALUES (1,1,'owner'),(2,2,'support'),
 (1,3,'admin');
SQL);
foreach([
    'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
    'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
    'cms_autosaves','cms_search_index','cms_media_collections',
    'cms_media_derivatives','cms_media_collection_items','cms_content_types',
    'cms_forms','cms_form_fields','cms_form_submissions','cms_reusable_blocks',
    'cms_block_patterns','cms_editorial_events','publication_jobs',
    'cms_custom_field_definitions','cms_custom_field_values'
] as $t)$db->exec('CREATE TABLE "'.$t.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
$check(SiteScopeMode::current($db)==='scoped','complete tenant schema');

$sessA='Phase11AlphaSyntheticSession0011';
$sessB='Phase11BetaSyntheticSession00022';
$sessThird='Phase11SuspendedSyntheticSession';
foreach([
    [1,1,$sessA],[2,2,$sessB],[3,3,$sessThird]
] as [$id,$user,$session]){
    $q=$db->prepare(
        'INSERT INTO active_sessions(id,user_id,session_hash) VALUES(:id,:user,:hash)'
    );
    $q->execute(['id'=>$id,'user'=>$user,'hash'=>hash('sha256',$session)]);
}
SiteScopedAdminSessionBinding::bind($db,1,1,$sessA,'alpha.example.test','password',1);
SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','password',1
);
$check((int)$db->query('SELECT COUNT(*) FROM cms_site_admin_sessions')->fetchColumn()===1,
       'tenant login binding not persisted');

$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,2,1,$sessA,'beta.example.test','password',1
),'tenant_session_binding_denied');
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test.evil','password',1
),'tenant_session_binding_denied');
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','vazin_id',1
),'tenant_session_binding_denied');
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','password',2
),'tenant_session_binding_denied');
$deny(fn()=>SiteScopedAdminSessionBinding::bind(
    $db,2,1,$sessA,'beta.example.test','password',1
),'tenant_session_scope_denied');
$deny(fn()=>SiteScopedAdminSessionBinding::bind(
    $db,1,3,$sessThird,'alpha.example.test','password',1
),'tenant_session_scope_denied');

SiteScopedAdminSessionBinding::bind($db,2,2,$sessB,'beta.example.test','vazin_id',2);
SiteScopedAdminSessionBinding::assertActive($db,2,2,$sessB,'beta.example.test','vazin_id',2);
$check((int)$db->query('SELECT COUNT(*) FROM cms_site_admin_sessions')->fetchColumn()===2,
       'other site session was not independent');
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,2,2,$sessA,'beta.example.test','password',1
),'tenant_session_binding_denied');

$db->exec("UPDATE cms_site_memberships SET site_role='author' WHERE site_id=1 AND user_id=1");
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','password',1
),'tenant_session_binding_denied');
$db->exec("UPDATE cms_site_memberships SET site_role='owner' WHERE site_id=1 AND user_id=1");
$db->exec("UPDATE cms_site_domains SET verified_at=NULL WHERE site_id=1");
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','password',1
),'tenant_session_binding_denied');
$db->exec("UPDATE cms_site_domains SET verified_at='2026-11-03 00:00:00' WHERE site_id=1");

$db->exec("UPDATE cms_site_admin_sessions SET expires_at='2000-01-01 00:00:00' WHERE site_id=1");
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','password',1
),'tenant_session_binding_denied');
$db->exec("UPDATE cms_site_admin_sessions SET expires_at='2099-01-01 00:00:00' WHERE site_id=1");
$db->exec("UPDATE users SET session_version=3 WHERE id=1");
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','password',1
),'tenant_session_binding_denied');
$db->exec("UPDATE users SET session_version=1 WHERE id=1");

SiteScopedAdminSessionBinding::revoke($db,1,$sessA);
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,1,1,$sessA,'alpha.example.test','password',1
),'tenant_session_binding_denied');
SiteScopedAdminSessionBinding::assertActive(
    $db,2,2,$sessB,'beta.example.test','vazin_id',2
);
$db->exec('DROP TABLE cms_site_admin_sessions');
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $db,2,2,$sessB,'beta.example.test','vazin_id',2
),'tenant_session_binding_denied');
$check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'foreign key failure');

echo "SITE_ADMIN_SESSION_BINDING=PASS two_sites,no_global_fallback,host_provider_role_version_revocation,missing_schema_denied\n";
