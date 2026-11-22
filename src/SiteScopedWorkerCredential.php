<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * A short-lived, explicitly issued worker CAPABILITY for one site + one job.
 *
 * Issuance requires a verified site domain, active site owner and a
 * registered local-only job with an active manager acting as run_as_user.
 * This is not an admin login, OAuth token or generic API token.
 * Only a hash of the 256-bit random secret is stored; the plaintext is
 * returned exactly once. No cron integration or network calls.
 */
final class SiteScopedWorkerCredential
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ) {
        if ($siteId<1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_worker_schema_unavailable');
        }
        try {
            $pdo->query('SELECT id,site_id,job_id,actor_id,token_hash,' .
                'expires_at,revoked_at FROM cms_site_worker_credentials WHERE 1=0');
        } catch(Throwable) {
            throw new RuntimeException('tenant_worker_schema_unavailable');
        }
    }

    /** @return array{id:int,token:string,expires_at:string} */
    public function issue(int $issuerId,int $jobId,int $ttlHours=24):array
    {
        $this->requireOwner($issuerId);
        if($jobId<1 || $ttlHours<1 || $ttlHours>168) {
            throw new RuntimeException('tenant_worker_grant_invalid');
        }
        $this->verifiedDomain();
        $job=$this->pdo->prepare(
            'SELECT j.id,j.run_as_user_id FROM cms_site_local_jobs j ' .
            'JOIN users u ON u.id=j.run_as_user_id ' .
            'JOIN cms_site_memberships m ON m.site_id=j.site_id ' .
            'AND m.user_id=j.run_as_user_id ' .
            'WHERE j.site_id=:site AND j.id=:job ' .
            "AND j.task_type='publish_due_local' AND j.is_active=TRUE " .
            'AND j.lock_token IS NULL AND j.locked_at IS NULL ' .
            "AND u.status='active' AND m.site_role IN('owner','admin') LIMIT 1"
        );
        $job->execute(['site'=>$this->siteId,'job'=>$jobId]);
        $row=$job->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new RuntimeException('tenant_worker_job_not_eligible');
        $token='vwc_'.bin2hex(random_bytes(32));
        $hash=hash('sha256',$token);
        $expires=gmdate('Y-m-d H:i:s',time()+$ttlHours*3600);
        $q=$this->pdo->prepare(
            'INSERT INTO cms_site_worker_credentials(' .
            'site_id,job_id,actor_id,issued_by,token_prefix,token_hash,task_type,expires_at) ' .
            "VALUES(:site,:job,:actor,:issuer,:prefix,:hash,'publish_due_local',:expires)"
        );
        try {
            $q->execute([
                'site'=>$this->siteId,'job'=>$jobId,
                'actor'=>$row['run_as_user_id'],'issuer'=>$issuerId,
                'prefix'=>substr($token,0,16),'hash'=>$hash,'expires'=>$expires
            ]);
        } catch(Throwable) {
            // Hide whether an existing active token or another constraint
            // failed; never disclose the plaintext in the exception.
            throw new RuntimeException('tenant_worker_active_grant_or_schema_conflict');
        }
        $lookup=$this->pdo->prepare(
            'SELECT id FROM cms_site_worker_credentials ' .
            'WHERE site_id=:site AND token_hash=:hash LIMIT 1'
        );
        $lookup->execute(['site'=>$this->siteId,'hash'=>$hash]);
        $id=(int)$lookup->fetchColumn();
        if($id<1)throw new RuntimeException('tenant_worker_grant_record_missing');
        return ['id'=>$id,'token'=>$token,'expires_at'=>$expires];
    }

    public function revoke(int $issuerId,int $credentialId):void
    {
        $this->requireOwner($issuerId);
        $q=$this->pdo->prepare(
            'UPDATE cms_site_worker_credentials SET revoked_at=CURRENT_TIMESTAMP ' .
            'WHERE id=:id AND site_id=:site AND revoked_at IS NULL'
        );
        $q->execute(['id'=>$credentialId,'site'=>$this->siteId]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_worker_grant_not_found');
    }

    /**
     * Authorization is reevaluated for EVERY use, including after a
     * membership/domain/issuer/actor revocation. It doesn't accept an
     * externally supplied actor ID or use a global role as proof.
     *
     * @return array{site_id:int,job_id:int,actor_id:int}
     */
    public function authenticate(string $token):array
    {
        if(preg_match('/^vwc_[a-f0-9]{64}$/D',$token)!==1) {
            throw new RuntimeException('tenant_worker_capability_denied');
        }
        $hash=hash('sha256',$token);
        try {
            $q=$this->pdo->prepare(
                'SELECT c.id,c.site_id,c.job_id,c.actor_id ' .
                'FROM cms_site_worker_credentials c ' .
                'JOIN cms_site_local_jobs j ON j.id=c.job_id AND j.site_id=c.site_id ' .
                'JOIN cms_sites s ON s.id=c.site_id ' .
                'JOIN users actor ON actor.id=c.actor_id ' .
                'JOIN cms_site_memberships am ON am.site_id=c.site_id ' .
                'AND am.user_id=c.actor_id ' .
                'JOIN users issuer ON issuer.id=c.issued_by ' .
                'JOIN cms_site_memberships im ON im.site_id=c.site_id ' .
                'AND im.user_id=c.issued_by ' .
                'WHERE c.site_id=:site AND c.token_hash=:hash ' .
                "AND c.task_type='publish_due_local' AND c.revoked_at IS NULL " .
                'AND c.expires_at>CURRENT_TIMESTAMP ' .
                "AND s.status IN('active','staging') " .
                "AND j.task_type='publish_due_local' AND j.is_active=TRUE " .
                'AND j.run_as_user_id=c.actor_id ' .
                "AND actor.status='active' AND am.site_role IN('owner','admin') " .
                "AND issuer.status='active' AND im.site_role='owner' " .
                'AND EXISTS(SELECT 1 FROM cms_site_domains d ' .
                'WHERE d.site_id=c.site_id AND d.verified_at IS NOT NULL) ' .
                'LIMIT 1'
            );
            $q->execute(['site'=>$this->siteId,'hash'=>$hash]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new RuntimeException('tenant_worker_capability_denied');
            $use=$this->pdo->prepare(
                'UPDATE cms_site_worker_credentials SET last_used_at=CURRENT_TIMESTAMP ' .
                'WHERE id=:id AND site_id=:site AND token_hash=:hash ' .
                'AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP'
            );
            $use->execute(['id'=>$row['id'],'site'=>$this->siteId,'hash'=>$hash]);
            if($use->rowCount()!==1)throw new RuntimeException('tenant_worker_capability_denied');
            return [
                'site_id'=>$this->siteId,
                'job_id'=>(int)$row['job_id'],
                'actor_id'=>(int)$row['actor_id']
            ];
        }catch(Throwable) {
            throw new RuntimeException('tenant_worker_capability_denied');
        }
    }

    /**
     * Only the bound local job is eligible. No global queue scan, no
     * external dispatch, no cron. Job claim/recovery remains phase8 fenced.
     */
    public function runLocal(string $token):array
    {
        $identity=$this->authenticate($token);
        $runner=new SiteScopedJobLeaseRunner($this->pdo,$this->siteId);
        $claim=$runner->claimNext(
            $identity['actor_id'],$identity['job_id']
        );
        if($claim===null)return ['status'=>'no_due_work','external_delivery'=>false];
        return $runner->executeClaim(
            $identity['actor_id'],$claim,
            function()use($token,$identity):void {
                $now=$this->authenticate($token);
                if($now['site_id']!==$identity['site_id'] ||
                   $now['job_id']!==$identity['job_id'] ||
                   $now['actor_id']!==$identity['actor_id']) {
                    throw new RuntimeException('tenant_worker_capability_denied');
                }
            }
        );
    }

    private function verifiedDomain():void
    {
        $q=$this->pdo->prepare(
            'SELECT 1 FROM cms_sites s JOIN cms_site_domains d ON d.site_id=s.id ' .
            "WHERE s.id=:site AND s.status IN('active','staging') " .
            'AND d.verified_at IS NOT NULL LIMIT 1'
        );
        $q->execute(['site'=>$this->siteId]);
        if(!$q->fetchColumn())throw new RuntimeException('tenant_worker_domain_unverified');
    }

    private function requireOwner(int $issuerId):void
    {
        if($issuerId<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT 1 FROM users u JOIN cms_site_memberships m ON m.user_id=u.id ' .
            'JOIN cms_sites s ON s.id=m.site_id ' .
            'WHERE u.id=:issuer AND s.id=:site ' .
            "AND u.status='active' AND m.site_role='owner' " .
            "AND s.status IN('active','staging') LIMIT 1"
        );
        $q->execute(['issuer'=>$issuerId,'site'=>$this->siteId]);
        if(!$q->fetchColumn())throw new RuntimeException('tenant_access_denied');
    }
}
