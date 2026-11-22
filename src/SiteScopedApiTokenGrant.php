<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Site-bound authorization for legacy API tokens; no automatic grants.
 *
 * The token itself remains in api_tokens. A new site grant requires an
 * explicitly present, active site manager and a verified site domain.
 * A token belongs to ONE site and ONE originating user. Host/site/actor
 * are all checked again at every use; cache/global role never authorizes it.
 */
final class SiteScopedApiTokenGrant
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ){
        if($siteId<1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_token_schema_unavailable');
        }
        try{
            $pdo->query('SELECT site_id,token_id,granted_by,revoked_at ' .
                'FROM cms_site_api_token_grants WHERE 1=0');
        }catch(Throwable){
            throw new RuntimeException('tenant_token_schema_unavailable');
        }
    }

    public function grant(int $actorId,int $tokenId):void
    {
        $this->requireManager($actorId);
        if($tokenId<1)throw new RuntimeException('tenant_token_invalid');
        $domain=$this->pdo->prepare(
            'SELECT 1 FROM cms_site_domains d JOIN cms_sites s ON s.id=d.site_id ' .
            "WHERE d.site_id=:site AND d.verified_at IS NOT NULL " .
            "AND s.status IN('active','staging') LIMIT 1"
        );
        $domain->execute(['site'=>$this->siteId]);
        if(!$domain->fetchColumn())throw new RuntimeException('tenant_verified_domain_required');

        $token=$this->pdo->prepare(
            'SELECT id,created_by FROM api_tokens WHERE id=:token ' .
            'AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)'
        );
        $token->execute(['token'=>$tokenId]);
        $row=$token->fetch(PDO::FETCH_ASSOC);
        if(!$row || (int)($row['created_by']??0)!==$actorId) {
            throw new RuntimeException('tenant_token_owner_denied');
        }

        $existing=$this->pdo->prepare(
            'SELECT site_id FROM cms_site_api_token_grants WHERE token_id=:token'
        );
        $existing->execute(['token'=>$tokenId]);
        $oldSite=$existing->fetchColumn();
        if($oldSite!==false && (int)$oldSite!==$this->siteId) {
            throw new RuntimeException('tenant_token_cross_site_binding_denied');
        }

        $q=$this->pdo->prepare(
            'INSERT INTO cms_site_api_token_grants(site_id,token_id,granted_by) ' .
            'VALUES(:site,:token,:actor) ' .
            'ON CONFLICT(site_id,token_id) DO UPDATE SET ' .
            'granted_by=excluded.granted_by,revoked_at=NULL ' .
            'WHERE cms_site_api_token_grants.site_id=excluded.site_id'
        );
        $q->execute(['site'=>$this->siteId,'token'=>$tokenId,'actor'=>$actorId]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_token_grant_conflict');
    }

    public function revoke(int $actorId,int $tokenId):void
    {
        $this->requireManager($actorId);
        $q=$this->pdo->prepare(
            'UPDATE cms_site_api_token_grants SET revoked_at=CURRENT_TIMESTAMP ' .
            'WHERE site_id=:site AND token_id=:token AND revoked_at IS NULL'
        );
        $q->execute(['site'=>$this->siteId,'token'=>$tokenId]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_token_grant_unavailable');
    }

    public static function assertCanUse(
        PDO $pdo,int $siteId,int $tokenId,?string $host=null
    ):void {
        if($siteId<1 || $tokenId<1)throw new RuntimeException('tenant_token_scope_denied');
        $host=strtolower(trim((string)($host??($_SERVER['HTTP_HOST']??''))));
        $host=preg_replace('/:[0-9]+$/D','',$host)??'';
        if($host==='' || strlen($host)>253 ||
            preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)!==1 ||
            str_contains($host,'..')) {
            throw new RuntimeException('tenant_token_scope_denied');
        }
        try {
            $q=$pdo->prepare(
                'SELECT 1 FROM cms_site_api_token_grants g ' .
                'JOIN cms_sites s ON s.id=g.site_id ' .
                'JOIN cms_site_domains d ON d.site_id=g.site_id ' .
                'JOIN api_tokens t ON t.id=g.token_id ' .
                'JOIN users u ON u.id=g.granted_by AND u.id=t.created_by ' .
                'JOIN cms_site_memberships m ON m.site_id=g.site_id ' .
                'AND m.user_id=g.granted_by ' .
                'WHERE g.site_id=:site AND g.token_id=:token ' .
                "AND s.status IN('active','staging') " .
                "AND lower(d.domain)=:host AND d.verified_at IS NOT NULL " .
                "AND u.status='active' AND m.site_role IN('owner','admin') " .
                'AND g.revoked_at IS NULL AND t.revoked_at IS NULL ' .
                'AND (t.expires_at IS NULL OR t.expires_at>CURRENT_TIMESTAMP) LIMIT 1'
            );
            $q->execute(['site'=>$siteId,'token'=>$tokenId,'host'=>$host]);
            if($q->fetchColumn())return;
        }catch(Throwable){
            // Missing grant schema must be indistinguishable from missing grant.
        }
        throw new RuntimeException('tenant_token_scope_denied');
    }

    private function requireManager(int $actorId):void
    {
        if($actorId<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id ' .
            'JOIN cms_sites s ON s.id=m.site_id ' .
            'WHERE m.site_id=:site AND m.user_id=:actor ' .
            "AND u.status='active' AND s.status IN('active','staging') LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        if(!in_array($q->fetchColumn(),['owner','admin'],true)){
            throw new RuntimeException('tenant_access_denied');
        }
    }
}
