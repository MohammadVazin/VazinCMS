<?php
declare(strict_types=1);

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void {
    $prefix='VazinCMS\\';
    if(!str_starts_with($class,$prefix))return;
    $path=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});

use VazinCMS\{IdentityProfile,SiteScopedOidcAdmission,
    SiteScopedAdminSessionBinding,SiteScopeMode,VazinIdentityLink};

$assert=static function(bool $yes,string $label):void {
    if(!$yes)throw new RuntimeException('SITE_OIDC_ADMISSION_FAIL: '.$label);
};
$deny=static function(callable $cb,string $expected,string $label)use($assert):void {
    try{$cb();}catch(RuntimeException $error){
        $assert($error->getMessage()===$expected,
            $label.' wrong reason '.$error->getMessage());
        return;
    }
    throw new RuntimeException('SITE_OIDC_ADMISSION_UNEXPECTED_SUCCESS: '.$label);
};
$pdo=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,verified_at TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,password_hash TEXT,
 role TEXT,status TEXT,session_version INTEGER);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,
 PRIMARY KEY(site_id,user_id));
CREATE TABLE active_sessions(
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),
 session_hash TEXT UNIQUE,revoked_at TEXT
);
CREATE TABLE cms_site_admin_sessions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,
 user_id INTEGER NOT NULL,active_session_id INTEGER NOT NULL UNIQUE
 REFERENCES active_sessions(id),session_hash TEXT NOT NULL UNIQUE,
 bound_host TEXT,auth_provider TEXT,session_version INTEGER,expires_at TEXT,
 revoked_at TEXT,
 FOREIGN KEY(site_id,user_id) REFERENCES cms_site_memberships(site_id,user_id)
);
CREATE TABLE user_identities(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL REFERENCES users(id),
 provider TEXT NOT NULL,provider_subject TEXT NOT NULL,
 email TEXT,email_verified INTEGER,linked_at TEXT,last_login_at TEXT,
 display_name TEXT,display_name_source TEXT,
 preferred_username TEXT,picture_url TEXT,locale TEXT,theme TEXT,
 profile_updated_at INTEGER,profile_source TEXT,preferences_shared INTEGER,
 profile_revision INTEGER,
 UNIQUE(provider,provider_subject),UNIQUE(user_id,provider)
);
INSERT INTO cms_sites VALUES(1,'alpha','active'),(2,'beta','active');
INSERT INTO cms_site_domains VALUES
 (1,'alpha.example.test','2026-11-03 00:00:00'),
 (2,'beta.example.test','2026-11-03 00:00:00');
INSERT INTO users VALUES
 (1,'Alpha manager','a@example.test','fake','owner','active',1),
 (2,'Beta support','b@example.test','fake','support','active',1),
 (3,'Alpha customer','c@example.test','fake','client','active',1),
 (4,'Alpha unlinked staff','d@example.test','fake','admin','active',1),
 (5,'Alpha suspended','e@example.test','fake','admin','suspended',1);
INSERT INTO cms_site_memberships VALUES
 (1,1,'owner'),(2,2,'support'),(1,3,'author'),
 (1,4,'admin'),(1,5,'admin');
SQL);
foreach([
'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
'cms_autosaves','cms_search_index','cms_media_collections',
'cms_media_derivatives','cms_media_collection_items','cms_content_types',
'cms_forms','cms_form_fields','cms_form_submissions','cms_reusable_blocks',
'cms_block_patterns','cms_editorial_events','publication_jobs',
'cms_custom_field_definitions','cms_custom_field_values'
]as$table)$pdo->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
$assert(SiteScopeMode::current($pdo)==='scoped','full schema');

$profile=static function(string $sub,string $email,bool $verified=true):array {
    return [
        'sub'=>$sub,'email'=>$email,'email_verified'=>$verified,
        'name'=>'Synthetic Identity User'
    ];
};
$owner=$profile('OIDC-KNOWN-A','a@example.test');
$beta=$profile('OIDC-KNOWN-B','b@example.test');
$client=$profile('OIDC-CLIENT-C','c@example.test');
VazinIdentityLink::resolve($pdo,$owner,1,false);
VazinIdentityLink::resolve($pdo,$beta,2,false);
VazinIdentityLink::resolve($pdo,$client,3,false);

$accepted=SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-KNOWN-A','changed-a@example.test'),1,
    null,'alpha.example.test'
);
$assert((int)$accepted['id']===1 && $accepted['role']==='owner',
        'prelinked staff subject denied');
$identityEmail=$pdo->query(
    "SELECT email FROM user_identities WHERE provider_subject='OIDC-KNOWN-A'"
)->fetchColumn();
$assert($identityEmail==='changed-a@example.test','verified identity not updated');
$assert($pdo->query('SELECT role FROM users WHERE id=1')->fetchColumn()==='owner',
        'profile escalated local role');

$beforeUsers=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$beforeLinks=(int)$pdo->query('SELECT COUNT(*) FROM user_identities')->fetchColumn();
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-UNKNOWN','new@example.test'),1,
    null,'alpha.example.test'
),'tenant_oidc_staff_not_authorized','unknown subject implicitly provisioned');
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-EMAIL-OWNER','a@example.test'),1,
    null,'alpha.example.test'
),'tenant_oidc_staff_not_authorized','email-based owner auto-claim');
$assert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$beforeUsers
    &&(int)$pdo->query('SELECT COUNT(*) FROM user_identities')->fetchColumn()===$beforeLinks,
    'rejected identity wrote any user/link rows');
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$beta,1,null,'alpha.example.test'
),'tenant_oidc_staff_not_authorized','prelinked staff belongs to another site');
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$client,1,null,'alpha.example.test'
),'tenant_oidc_staff_not_authorized','client identity gained staff admin');
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-KNOWN-A','a@example.test',false),1,null,'alpha.example.test'
),'برای ورود، Vazin ID باید ایمیل تأییدشده ارائه کند.','unverified email');

$session='Phase12LinkedStaffSyntheticSession1234';
$pdo->prepare(
    'INSERT INTO active_sessions(id,user_id,session_hash) VALUES(?,?,?)'
)->execute([10,4,hash('sha256',$session)]);
SiteScopedAdminSessionBinding::bind(
    $pdo,1,4,$session,'alpha.example.test','password',1
);
$unlinked=$profile('OIDC-EXPLICIT-STAFF','d@example.test');
$link=SiteScopedOidcAdmission::resolve(
    $pdo,$unlinked,1,4,'alpha.example.test',$session,'password',1
);
$assert((int)$link['id']===4,'explicit linking not bound to staff');
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-OTHER-STAFF','other@example.test'),
    1,4,'alpha.example.test','wrong-session','password',1
),'tenant_oidc_link_session_denied','invalid browser session linked account');
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-OTHER-STAFF','other@example.test'),
    1,2,'alpha.example.test',$session,'password',1
),'tenant_oidc_link_session_denied','cross-site linking user');
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-KNOWN-A','a@example.test'),
    1,4,'alpha.example.test',$session,'password',1
),'tenant_oidc_staff_not_authorized','conflicting known subject relink');

$next='Phase12OAuthRotatedSyntheticSession4567';
SiteScopedAdminSessionBinding::rotateForOAuthLink(
    $pdo,1,4,$session,$next,'alpha.example.test','password',1
);
$deny(fn()=>SiteScopedAdminSessionBinding::assertActive(
    $pdo,1,4,$session,'alpha.example.test','password',1
),'tenant_session_binding_denied','old oauth link session still active');
SiteScopedAdminSessionBinding::assertActive(
    $pdo,1,4,$next,'alpha.example.test','password',1
);
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-SECOND-LINK','new@example.test'),
    1,4,'alpha.example.test',$session,'password',1
),'tenant_oidc_link_session_denied','old session bypassed explicit linking');

$pdo->exec("UPDATE cms_site_memberships SET site_role='author'
 WHERE site_id=1 AND user_id=4");
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-NEW-DOWNGRADED','new@example.test'),
    1,4,'alpha.example.test',$next,'password',1
),'tenant_oidc_link_session_denied','downgraded staff retained link');
$pdo->exec("UPDATE cms_site_memberships SET site_role='admin'
 WHERE site_id=1 AND user_id=4");
SiteScopedAdminSessionBinding::revoke($pdo,4,$next);
$deny(fn()=>SiteScopedOidcAdmission::resolve(
    $pdo,$profile('OIDC-NEW-REVOKED','new@example.test'),
    1,4,'alpha.example.test',$next,'password',1
),'tenant_oidc_link_session_denied','revoked link session retained permission');

$assert((int)$pdo->query('SELECT COUNT(*) FROM user_identities')->fetchColumn()===4,
        'untrusted identity created a row');
$assert($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],
        'FK integrity failed');
echo "SITE_SCOPED_OIDC_ADMISSION=PASS existing_staff,anonymous_denied,no_auto_provision,explicit_link,role_revocation,session_rotation\n";
