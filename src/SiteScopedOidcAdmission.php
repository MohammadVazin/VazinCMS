<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

/**
 * Vazin ID admission to a scoped CMS admin site, BEFORE any identity writes.
 *
 * An OIDC subject without a pre-existing explicit staff/site relationship
 * cannot auto-create a local client/user, cannot claim owner by email and
 * cannot acquire a site admin session. An explicit in-browser link is allowed
 * only for an authenticated already site-bound staff member.
 */
final class SiteScopedOidcAdmission
{
    public static function resolve(
        PDO $pdo,
        array $profile,
        int $siteId,
        ?int $linkUserId,
        string $host,
        ?string $sessionId=null,
        ?string $sessionProvider=null,
        ?int $sessionVersion=null
    ): array {
        if($siteId<1 || !SiteScopeMode::active($pdo)){
            throw new RuntimeException('tenant_oidc_scope_unavailable');
        }
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && !DatabaseTransaction::owns($pdo)){
            throw new RuntimeException('tenant_oidc_transaction_required');
        }
        $normalized=IdentityProfile::normalize($profile);
        $lookup=$pdo->prepare(
            'SELECT u.id FROM user_identities i ' .
            'JOIN users u ON u.id=i.user_id ' .
            "WHERE i.provider='vazin_id' AND i.provider_subject=:sub LIMIT 1"
        );
        $lookup->execute(['sub'=>$normalized['sub']]);
        $mapped=$lookup->fetchColumn();
        if($mapped!==false){
            $actorId=(int)$mapped;
            if($linkUserId!==null && $actorId!==$linkUserId){
                throw new RuntimeException('tenant_oidc_staff_not_authorized');
            }
        }elseif($linkUserId!==null && $linkUserId>0){
            // The OAuth flow includes a link_user_id only when the user
            // explicitly started linking an existing authenticated account.
            self::assertLinkingSession(
                $pdo,$siteId,$linkUserId,$host,
                (string)$sessionId,(string)$sessionProvider,(int)$sessionVersion
            );
            $actorId=$linkUserId;
        }else{
            // In a tenant-scoped CMS unknown subjects MUST NOT trigger the
            // global auto_provision=1 path (which creates an orphan client).
            throw new RuntimeException('tenant_oidc_staff_not_authorized');
        }

        self::assertStaff($pdo,$siteId,$actorId,$host);
        if($linkUserId!==null){
            // Also require active browser binding when linking an already
            // mapped identity, so callback state cannot bypass revocation.
            self::assertLinkingSession(
                $pdo,$siteId,$linkUserId,$host,
                (string)$sessionId,(string)$sessionProvider,(int)$sessionVersion
            );
        }
        // Admission is complete: preserve existing IdentityProfile rules
        // and explicitly forbid auto-provision for scoped staff.
        return VazinIdentityLink::resolve(
            $pdo,$normalized,$linkUserId,false
        );
    }

    public static function assertLinkingSession(
        PDO $pdo,int $siteId,int $userId,string $host,
        string $sessionId,string $provider,int $version
    ): void {
        try{
            self::assertStaff($pdo,$siteId,$userId,$host);
            SiteScopedAdminSessionBinding::assertActive(
                $pdo,$siteId,$userId,$sessionId,$host,$provider,$version
            );
        }catch(\Throwable){
            throw new RuntimeException('tenant_oidc_link_session_denied');
        }
    }

    private static function assertStaff(
        PDO $pdo,int $siteId,int $userId,string $host
    ): void {
        if($userId<1)throw new RuntimeException('tenant_oidc_staff_not_authorized');
        try{
            // SiteScopedSessionGuard proves explicit site manager/support
            // membership and a verified exact Host, not a global role.
            SiteScopedSessionGuard::assertAdminAccess(
                $pdo,$siteId,$userId,$host
            );
            $role=$pdo->prepare(
                "SELECT role FROM users WHERE id=:id AND status='active' LIMIT 1"
            );
            $role->execute(['id'=>$userId]);
            if(in_array($role->fetchColumn(),['owner','admin','support'],true)){
                if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
                   && DatabaseTransaction::owns($pdo)){
                    // Lock ALL authorization rows inside the same callback
                    // transaction as identity mutation + site session insert.
                    // A concurrent role/domain revocation must either land
                    // before this check (denied) or after COMMIT (trigger
                    // revokes the newly created binding).
                    $normalized=strtolower(trim($host));
                    $normalized=preg_replace('/:[0-9]+$/D','',$normalized)??'';
                    $lock=$pdo->prepare(
                        'SELECT u.id FROM cms_sites s ' .
                        'JOIN cms_site_domains d ON d.site_id=s.id ' .
                        'JOIN cms_site_memberships m ON m.site_id=s.id ' .
                        'JOIN users u ON u.id=m.user_id ' .
                        'WHERE s.id=:site AND u.id=:actor AND lower(d.domain)=:host ' .
                        "AND s.status IN('active','staging') " .
                        'AND d.verified_at IS NOT NULL ' .
                        "AND u.status='active' " .
                        "AND u.role IN('owner','admin','support') " .
                        "AND m.site_role IN('owner','admin','support') " .
                        'LIMIT 1 FOR UPDATE OF s,d,m,u'
                    );
                    $lock->execute([
                        'site'=>$siteId,'actor'=>$userId,'host'=>$normalized
                    ]);
                    if(!$lock->fetchColumn()){
                        throw new RuntimeException('tenant_oidc_staff_not_authorized');
                    }
                }
                return;
            }
        }catch(\Throwable){
            // Normalize all site/role/subject failures to a generic reason;
            // never enumerate staff accounts through UserInfo.
        }
        throw new RuntimeException('tenant_oidc_staff_not_authorized');
    }
}
