<?php
declare(strict_types=1);

/** Candidate-only post-COMMIT file-session failure compensation contract. */
$root=dirname(__DIR__);
require_once $root.'/src/DatabaseTransaction.php';
require_once $root.'/src/SessionSecurity.php';
require_once $root.'/src/SiteScopedSessionPersistenceGuard.php';

use VazinCMS\SiteScopedSessionPersistenceGuard;
$test=static function(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('SESSION_PERSISTENCE_FAIL: '.$label);
};
$deny=static function(callable $work,string $expected)use($test):void{
    try{$work();}catch(RuntimeException $e){
        $test($e->getMessage()===$expected,
              'wrong error code '.$e->getMessage());
        return;
    }
    throw new RuntimeException('SESSION_PERSISTENCE_UNEXPECTED_SUCCESS '.$expected);
};
$pdo=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$pdo->exec(<<<'SQL'
CREATE TABLE cms_site_admin_sessions (
 id INTEGER PRIMARY KEY,site_id INTEGER,user_id INTEGER,
 session_hash TEXT,revoked_at TEXT);
CREATE TABLE active_sessions (
 id INTEGER PRIMARY KEY,user_id INTEGER,session_hash TEXT,revoked_at TEXT);
SQL);
$session='Phase14SyntheticSessionFailedPostCommitA';
$hash=hash('sha256',$session);
$insert=static function()use($pdo,$hash):void {
    $pdo->prepare('INSERT OR REPLACE INTO cms_site_admin_sessions(' .
        'id,site_id,user_id,session_hash,revoked_at) VALUES(1,1,2,:hash,NULL)')
        ->execute(['hash'=>$hash]);
    $pdo->prepare('INSERT OR REPLACE INTO active_sessions(' .
        'id,user_id,session_hash,revoked_at) VALUES(1,2,:hash,NULL)')
        ->execute(['hash'=>$hash]);
};
$count=static function(string $table)use($pdo):int{
    return (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE revoked_at IS NULL")
                    ->fetchColumn();
};
$insert();
$persisted=0;
SiteScopedSessionPersistenceGuard::persistOrRevoke(
    $pdo,1,2,$session,static function()use(&$persisted):bool{
        $persisted++;return true;
    }
);
$test($persisted===1 && $count('active_sessions')===1 &&
      $count('cms_site_admin_sessions')===1,'normal callback success');
$deny(static fn()=>SiteScopedSessionPersistenceGuard::persistOrRevoke(
    $pdo,1,2,$session,static fn():bool=>false
),'tenant_oidc_session_persistence_failed');
$test($count('active_sessions')===0 &&
      $count('cms_site_admin_sessions')===0,
      'failed file-store persisted a usable database session');

$pdo->exec('DELETE FROM cms_site_admin_sessions');
$pdo->exec('DELETE FROM active_sessions');
$insert();
$pdo->exec('DELETE FROM cms_site_admin_sessions');
$deny(static fn()=>SiteScopedSessionPersistenceGuard::persistOrRevoke(
    $pdo,1,2,$session,static fn():bool=>false
),'tenant_oidc_session_persistence_ambiguous');
$test($count('active_sessions')===1,
      'partial-revocation risk must not claim success in ambiguous case');

$pdo->beginTransaction();
$deny(static fn()=>SiteScopedSessionPersistenceGuard::persistOrRevoke(
    $pdo,1,2,$session,static fn():bool=>true
),'tenant_session_persistence_transaction_unfinished');
$pdo->rollBack();
$deny(static fn()=>SiteScopedSessionPersistenceGuard::persistOrRevoke(
    $pdo,1,2,'bad id',static fn():bool=>true
),'tenant_session_persistence_invalid');
echo "SITE_SESSION_POST_COMMIT_COMPENSATION=PASS persisted_success,revoke_both,ambiguity_flag,ambient_tx_denied\n";
