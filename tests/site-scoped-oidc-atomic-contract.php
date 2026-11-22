<?php
declare(strict_types=1);

/**
 * Phase13: regression of atomic OIDC admission under permission revocation.
 * Only synthetic in-memory SQLite, never a production account/database.
 */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name) use($root):void {
    $prefix='VazinCMS\\';
    if(!str_starts_with($name,$prefix))return;
    $path=$root.'/src/'.str_replace('\\','/',substr($name,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
use VazinCMS\{DatabaseTransaction,SiteScopedOidcAdmission,SiteScopedSessionGuard};

$check=static function(bool $good,string $label):void {
    if(!$good)throw new RuntimeException('ATOMIC_OIDC_CONTRACT_FAIL: '.$label);
};
$db=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec(<<<'SQL'
CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
CREATE TABLE users(
 id INTEGER PRIMARY KEY,name TEXT,email TEXT,password_hash TEXT,
 role TEXT,status TEXT,session_version INTEGER
);
CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,verified_at TEXT);
CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT,
 PRIMARY KEY(site_id,user_id));
CREATE TABLE user_identities(
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),
 provider TEXT NOT NULL,provider_subject TEXT NOT NULL,
 email TEXT,email_verified INTEGER,linked_at TEXT,last_login_at TEXT,
 display_name TEXT,display_name_source TEXT,preferred_username TEXT,
 picture_url TEXT,locale TEXT,theme TEXT,profile_updated_at INTEGER,
 profile_source TEXT,preferences_shared INTEGER,profile_revision INTEGER,
 UNIQUE(provider,provider_subject),UNIQUE(user_id,provider)
);
CREATE TABLE active_sessions(
 id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id),
 session_hash TEXT,revoked_at TEXT
);
INSERT INTO cms_sites VALUES(1,'qa','active');
INSERT INTO users VALUES
 (1,'QA Owner','owner@example.test','no-secret','owner','active',1);
INSERT INTO cms_site_domains VALUES(1,'qa.example.test','2026-11-03 00:00:00');
INSERT INTO cms_site_memberships VALUES(1,1,'owner');
INSERT INTO user_identities(id,user_id,provider,provider_subject,email,email_verified,
display_name,display_name_source) VALUES
(1,1,'vazin_id','qa-subject','original@example.test',1,'QA Identity','vazin_id');
INSERT INTO active_sessions VALUES(1,1,'synthetic-hash',NULL);
CREATE TRIGGER qa_revoke_during_identity_update
AFTER UPDATE OF email ON user_identities
WHEN NEW.provider_subject='qa-subject'
BEGIN
 UPDATE cms_site_memberships SET site_role='author'
 WHERE site_id=1 AND user_id=1;
END;
SQL);
foreach([
'cms_pages','cms_media','cms_menu_items','cms_settings','cms_redirects',
'cms_taxonomies','cms_terms','cms_term_relationships','cms_page_revisions',
'cms_autosaves','cms_search_index','cms_media_collections',
'cms_media_derivatives','cms_media_collection_items','cms_content_types',
'cms_forms','cms_form_fields','cms_form_submissions','cms_reusable_blocks',
'cms_block_patterns','cms_editorial_events','publication_jobs',
'cms_custom_field_definitions','cms_custom_field_values'
]as$table)$db->exec('CREATE TABLE "'.$table.'" (id INTEGER PRIMARY KEY,site_id INTEGER)');
$before=$db->query('SELECT email FROM user_identities WHERE id=1')->fetchColumn();
$failed=false;
try {
    DatabaseTransaction::immediate($db,static function()use($db):void{
        $profile=[
            'sub'=>'qa-subject',
            'email'=>'revocation-race@example.test',
            'email_verified'=>true,
            'name'=>'QA Identity'
        ];
        SiteScopedOidcAdmission::resolve(
            $db,$profile,1,null,'qa.example.test'
        );
        // This is what the real Auth::login checks before it creates a
        // site-bound session; the test trigger revoked role in between.
        SiteScopedSessionGuard::assertAdminAccess(
            $db,1,1,'qa.example.test'
        );
        throw new RuntimeException('tenant_test_should_not_authenticate');
    });
} catch(RuntimeException $error) {
    $failed=$error->getMessage()==='tenant_session_scope_denied';
}
$check($failed,'mid-callback membership downgrade not refused');
$check($db->query('SELECT email FROM user_identities WHERE id=1')->fetchColumn()===$before,
       'identity metadata not rolled back');
$check($db->query('SELECT site_role FROM cms_site_memberships WHERE site_id=1')
          ->fetchColumn()==='owner','membership change not rolled back');
$check((int)$db->query('SELECT COUNT(*) FROM active_sessions')->fetchColumn()===1,
       'orphan authentication row created');
$check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],
       'foreign key integrity broken');
$controller=(string)file_get_contents($root.'/src/Controllers/AuthController.php');
$atomic=(string)file_get_contents($root.'/src/SiteScopedOidcAtomicLogin.php');
$auth=(string)file_get_contents($root.'/src/Auth.php');
$check(str_contains($controller,'SiteScopedOidcAtomicLogin::complete('),
       'scoped controller bypasses atomic callback');
$check(str_contains($atomic,'DatabaseTransaction::immediate(')
    &&str_contains($atomic,"Auth::login((int)\$user['id'],'vazin_id')"),
       'identity and authentication ledgers not in one callback transaction');
$check(str_contains($auth,'$callbackOwned = DatabaseTransaction::owns($pdo)')
    &&str_contains($auth,'if (!$callbackOwned) $pdo->commit();'),
       'Auth::login still commits the outer callback transaction independently');
echo "SITE_OIDC_ATOMIC_ROLLBACK=PASS identity,role,session_ledger,callback_transaction_contract\n";
