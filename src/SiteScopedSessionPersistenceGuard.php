<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Best-effort post-COMMIT session-persistence compensation for OIDC callbacks.
 *
 * DB auth ledgers have already committed, but the PHP file session has not
 * necessarily been written. On failure, revoke BOTH ledgers in a separate
 * transaction, discard the cookie and deny the callback. An uncertain
 * revocation is reported as ambiguous, never silently accepted.
 *
 * PHP session files and DB cannot share a real atomic commit: this mechanism
 * reduces risk but does not claim exactly-once/durable commit guarantees.
 */
final class SiteScopedSessionPersistenceGuard
{
    /**
     * @param null|callable():bool $persist QA injection only. Omit in HTTP.
     */
    public static function persistOrRevoke(
        PDO $pdo,
        int $siteId,
        int $userId,
        string $sessionId,
        ?callable $persist=null
    ):void {
        if($siteId<1 || $userId<1 ||
           preg_match('/\A[A-Za-z0-9,-]{16,256}\z/D',$sessionId)!==1){
            throw new RuntimeException('tenant_session_persistence_invalid');
        }
        if($pdo->inTransaction() || DatabaseTransaction::owns($pdo)){
            throw new RuntimeException('tenant_session_persistence_transaction_unfinished');
        }
        try{
            if($persist!==null){
                if($persist()!==true){
                    throw new RuntimeException('tenant_test_persistence_failure');
                }
            }else{
                SessionSecurity::persistAndSuspend();
            }
            return;
        }catch(Throwable){
            // Ensure a post-COMMIT failure never becomes an authenticated
            // partial success. Revoke both session ledgers, not the account.
            $revoked=false;
            try {
                $pdo->beginTransaction();
                $hash=hash('sha256',$sessionId);
                $q=$pdo->prepare(
                    'UPDATE cms_site_admin_sessions SET revoked_at=CURRENT_TIMESTAMP ' .
                    'WHERE site_id=:site AND user_id=:user AND session_hash=:hash ' .
                    'AND revoked_at IS NULL'
                );
                $q->execute(['site'=>$siteId,'user'=>$userId,'hash'=>$hash]);
                if($q->rowCount()!==1){
                    throw new RuntimeException('tenant_session_persistence_site_ledger_missing');
                }
                $q=$pdo->prepare(
                    'UPDATE active_sessions SET revoked_at=CURRENT_TIMESTAMP ' .
                    'WHERE user_id=:user AND session_hash=:hash AND revoked_at IS NULL'
                );
                $q->execute(['user'=>$userId,'hash'=>$hash]);
                if($q->rowCount()!==1){
                    throw new RuntimeException('tenant_session_persistence_global_ledger_missing');
                }
                $pdo->commit();
                $revoked=true;
            }catch(Throwable) {
                if($pdo->inTransaction()){
                    try{$pdo->rollBack();}catch(Throwable){}
                }
            }
            try{SessionSecurity::destroy();}catch(Throwable){}
            throw new RuntimeException(
                $revoked
                ? 'tenant_oidc_session_persistence_failed'
                : 'tenant_oidc_session_persistence_ambiguous'
            );
        }
    }
}
