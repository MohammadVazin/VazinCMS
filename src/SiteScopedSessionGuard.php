<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Per-request site membership guard on authenticated admin routes.
 *
 * Legacy Auth still uses a global account/session table. Until an explicit
 * site-bound session/SSO rollout is accepted, this guard enforces current
 * site membership on every authenticated admin request; it never guesses
 * membership from global role, email, identity claims, or site owner.
 */
final class SiteScopedSessionGuard
{
    public static function assertAdminAccess(
        PDO $pdo,int $siteId,int $userId,?string $host=null
    ):void {
        if($siteId<1 || $userId<1){
            throw new RuntimeException('tenant_session_scope_denied');
        }
        $host=strtolower(trim((string)($host??($_SERVER['HTTP_HOST']??''))));
        $host=preg_replace('/:[0-9]+$/D','',$host)??'';
        if($host==='' || strlen($host)>253 ||
           preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)!==1 ||
           str_contains($host,'..')) {
            throw new RuntimeException('tenant_session_scope_denied');
        }
        try{
            $q=$pdo->prepare(
                'SELECT 1 FROM cms_sites s ' .
                'JOIN cms_site_domains d ON d.site_id=s.id ' .
                'JOIN cms_site_memberships m ON m.site_id=s.id ' .
                'JOIN users u ON u.id=m.user_id ' .
                'WHERE s.id=:site AND u.id=:user AND lower(d.domain)=:host ' .
                "AND s.status IN('active','staging') AND d.verified_at IS NOT NULL " .
                "AND u.status='active' " .
                "AND m.site_role IN('owner','admin','support') LIMIT 1"
            );
            $q->execute(['site'=>$siteId,'user'=>$userId,'host'=>$host]);
            if($q->fetchColumn())return;
        }catch(Throwable){
            // Never treat an absent/malformed membership or domain schema
            // as an implicit authorization.
        }
        throw new RuntimeException('tenant_session_scope_denied');
    }
}
