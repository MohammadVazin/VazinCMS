<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Per-site binding for an existing, rotated PHP authentication session.
 *
 * The global Auth session and active_sessions row are NOT authoritative for
 * any tenant admin action by themselves. A binding can only be minted during
 * an authenticated login with an exact verified Host, active user, current
 * session_version and explicit site membership.
 *
 * No historical session or global owner automatically receives a grant.
 */
final class SiteScopedAdminSessionBinding
{
    public static function bind(
        PDO $pdo,int $siteId,int $userId,string $sessionId,string $host,
        string $provider,int $sessionVersion
    ):void {
        if(!SiteScopeMode::active($pdo))throw new RuntimeException('tenant_session_schema_unavailable');
        self::assertInputs($siteId,$userId,$sessionId,$provider,$sessionVersion);
        $normalized=self::host($host);
        SiteScopedSessionGuard::assertAdminAccess($pdo,$siteId,$userId,$normalized);
        $hash=hash('sha256',$sessionId);

        $q=$pdo->prepare(
            'SELECT a.id FROM active_sessions a JOIN users u ON u.id=a.user_id ' .
            'WHERE a.user_id=:uid AND a.session_hash=:hash AND a.revoked_at IS NULL ' .
            "AND u.status='active' AND u.session_version=:version LIMIT 1"
        );
        $q->execute(['uid'=>$userId,'hash'=>$hash,'version'=>$sessionVersion]);
        $activeId=$q->fetchColumn();
        if(!$activeId)throw new RuntimeException('tenant_session_active_record_required');

        // Compute expiration in the database clock domain to avoid a PHP
        // UTC vs PostgreSQL session-timezone mismatch on TIMESTAMP columns.
        $expirySql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            ? "CURRENT_TIMESTAMP + INTERVAL '12 hours'"
            : "datetime('now','+12 hours')";
        $insert=$pdo->prepare(
            'INSERT INTO cms_site_admin_sessions(site_id,user_id,active_session_id,' .
            'session_hash,bound_host,auth_provider,session_version,expires_at) ' .
            'VALUES(:site,:user,:active,:hash,:host,:provider,:version,' . $expirySql . ')'
        );
        try {
            $insert->execute([
                'site'=>$siteId,'user'=>$userId,'active'=>$activeId,
                'hash'=>$hash,'host'=>$normalized,'provider'=>$provider,
                'version'=>$sessionVersion
            ]);
        } catch(Throwable) {
            throw new RuntimeException('tenant_session_binding_conflict');
        }
        if($insert->rowCount()!==1)throw new RuntimeException('tenant_session_binding_failed');
    }

    /**
     * Call on every authenticated tenant admin request, after the global
     * Auth::user() check. No implicit first-use creation is ever permitted.
     */
    public static function assertActive(
        PDO $pdo,int $siteId,int $userId,string $sessionId,string $host,
        string $provider,int $sessionVersion
    ):void {
        self::assertInputs($siteId,$userId,$sessionId,$provider,$sessionVersion);
        $host=self::host($host);
        $hash=hash('sha256',$sessionId);
        try {
            $q=$pdo->prepare(
                'SELECT 1 FROM cms_site_admin_sessions b ' .
                'JOIN active_sessions a ON a.id=b.active_session_id ' .
                'AND a.user_id=b.user_id AND a.session_hash=b.session_hash ' .
                'JOIN cms_sites s ON s.id=b.site_id ' .
                'JOIN cms_site_domains d ON d.site_id=b.site_id ' .
                'JOIN cms_site_memberships m ON m.site_id=b.site_id AND m.user_id=b.user_id ' .
                'JOIN users u ON u.id=b.user_id ' .
                'WHERE b.site_id=:site AND b.user_id=:user AND b.session_hash=:hash ' .
                'AND b.bound_host=:host AND lower(d.domain)=:same_host ' .
                'AND b.auth_provider=:provider AND b.session_version=:version ' .
                'AND b.revoked_at IS NULL AND b.expires_at>CURRENT_TIMESTAMP ' .
                'AND a.revoked_at IS NULL ' .
                "AND s.status IN('active','staging') AND d.verified_at IS NOT NULL " .
                "AND m.site_role IN('owner','admin','support') AND u.status='active' " .
                'AND u.session_version=b.session_version LIMIT 1'
            );
            $q->execute([
                'site'=>$siteId,'user'=>$userId,'hash'=>$hash,
                'host'=>$host,'same_host'=>$host,'provider'=>$provider,
                'version'=>$sessionVersion
            ]);
            if($q->fetchColumn())return;
        } catch(Throwable) {
            // A missing ledger or malformed site session is never accepted.
        }
        throw new RuntimeException('tenant_session_binding_denied');
    }

    /**
     * OAuth linking rotates the PHP session ID before redirecting to Vazin ID.
     * Move BOTH ledger hashes together, otherwise the callback cannot
     * authenticate its original linking actor. This never creates a grant.
     */
    public static function rotateForOAuthLink(
        PDO $pdo,int $siteId,int $userId,string $oldSessionId,
        string $newSessionId,string $host,string $provider,int $version
    ): void {
        self::assertInputs($siteId,$userId,$oldSessionId,$provider,$version);
        self::assertInputs($siteId,$userId,$newSessionId,$provider,$version);
        if(hash_equals($oldSessionId,$newSessionId)){
            throw new RuntimeException('tenant_oidc_rotation_invalid');
        }
        if($pdo->inTransaction()){
            throw new RuntimeException('tenant_oidc_rotation_ambient_transaction');
        }
        self::assertActive(
            $pdo,$siteId,$userId,$oldSessionId,$host,$provider,$version
        );
        $oldHash=hash('sha256',$oldSessionId);
        $newHash=hash('sha256',$newSessionId);
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare(
                'UPDATE active_sessions SET session_hash=:new ' .
                'WHERE user_id=:user AND session_hash=:old AND revoked_at IS NULL'
            );
            $q->execute(['new'=>$newHash,'user'=>$userId,'old'=>$oldHash]);
            if($q->rowCount()!==1){
                throw new RuntimeException('tenant_oidc_rotation_active_session_lost');
            }
            $q=$pdo->prepare(
                'UPDATE cms_site_admin_sessions SET session_hash=:new ' .
                'WHERE site_id=:site AND user_id=:user AND session_hash=:old ' .
                'AND revoked_at IS NULL AND bound_host=:host'
            );
            $q->execute([
                'new'=>$newHash,'site'=>$siteId,'user'=>$userId,
                'old'=>$oldHash,'host'=>self::host($host)
            ]);
            if($q->rowCount()!==1){
                throw new RuntimeException('tenant_oidc_rotation_site_binding_lost');
            }
            $pdo->commit();
        }catch(\Throwable $error){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $error;
        }
    }

    public static function revoke(PDO $pdo,int $userId,string $sessionId):void
    {
        if($userId<1 || !self::validSessionId($sessionId)){
            throw new RuntimeException('tenant_session_binding_denied');
        }
        $q=$pdo->prepare(
            'UPDATE cms_site_admin_sessions SET revoked_at=CURRENT_TIMESTAMP ' .
            'WHERE user_id=:user AND session_hash=:hash AND revoked_at IS NULL'
        );
        $q->execute(['user'=>$userId,'hash'=>hash('sha256',$sessionId)]);
    }

    private static function assertInputs(
        int $siteId,int $userId,string $sessionId,string $provider,int $version
    ):void {
        if($siteId<1 || $userId<1 || $version<1 || !self::validSessionId($sessionId) ||
            !in_array($provider,['password','vazin_id'],true)) {
            throw new RuntimeException('tenant_session_binding_denied');
        }
    }

    private static function validSessionId(string $id):bool
    {
        return preg_match('/^[A-Za-z0-9,-]{16,256}$/D',$id)===1;
    }

    private static function host(string $raw):string
    {
        $host=strtolower(trim($raw));
        $host=preg_replace('/:[0-9]+$/D','',$host)??'';
        if($host==='' || strlen($host)>253 || str_contains($host,'..') ||
            preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)!==1) {
            throw new RuntimeException('tenant_session_binding_denied');
        }
        return $host;
    }
}
