<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Isolated, MANUAL local publication queue.
 *
 * No cron route, webhook, external sending, account or module execution.
 * Caller must pass an active site-approved manager ID and a site ID.
 * Jobs are locked atomically; lost/uncertain lock remains blocked for
 * explicit operator recovery, never auto-replayed.
 */
final class SiteScopedLocalJobQueue
{
    private readonly SiteScopedEditorialWorkflow $workflow;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ) {
        if($siteId<1 || !SiteScopeMode::active($pdo)){
            throw new RuntimeException('tenant_job_schema_unavailable');
        }
        $site=$pdo->prepare(
            "SELECT 1 FROM cms_sites WHERE id=:site AND status IN('active','staging')"
        );
        $site->execute(['site'=>$siteId]);
        if(!$site->fetchColumn())throw new RuntimeException('tenant_site_unavailable');
        try{
            $pdo->query('SELECT id,site_id,job_key,run_as_user_id,lock_token ' .
                'FROM cms_site_local_jobs WHERE 1=0');
            $pdo->query('SELECT site_id,job_id,run_token FROM cms_site_local_job_runs WHERE 1=0');
        }catch(Throwable){
            throw new RuntimeException('tenant_job_schema_unavailable');
        }
        $this->workflow=new SiteScopedEditorialWorkflow($pdo,$siteId);
    }

    public function register(
        int $actorId,
        string $jobKey,
        int $intervalMinutes,
        string $nextRunAt,
        bool $enable=false
    ):int {
        $this->authorize($actorId);
        if(!preg_match('/^[a-z0-9][a-z0-9-]{2,95}$/D',$jobKey) ||
           $intervalMinutes<5 || $intervalMinutes>525600 ||
           preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$nextRunAt)!==1 ||
           strtotime($nextRunAt)===false) {
            throw new RuntimeException('tenant_job_input_invalid');
        }
        // A pre-existing running job is never modified by a second request.
        $q=$this->pdo->prepare(
            "INSERT INTO cms_site_local_jobs(site_id,job_key,task_type,is_active," .
            "interval_minutes,run_as_user_id,next_run_at,created_by) " .
            "VALUES(:site,:key,'publish_due_local',:enabled,:interval,:actor,:next,:creator) " .
            'ON CONFLICT(site_id,job_key) DO UPDATE SET ' .
            'is_active=excluded.is_active,interval_minutes=excluded.interval_minutes,' .
            'run_as_user_id=excluded.run_as_user_id,next_run_at=excluded.next_run_at,' .
            'updated_at=CURRENT_TIMESTAMP WHERE cms_site_local_jobs.site_id=excluded.site_id ' .
            'AND cms_site_local_jobs.lock_token IS NULL'
        );
        $q->execute([
            'site'=>$this->siteId,'key'=>$jobKey,
            'enabled'=>$enable?1:0,'interval'=>$intervalMinutes,
            'actor'=>$actorId,'next'=>$nextRunAt,'creator'=>$actorId
        ]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_job_locked_or_conflict');
        $read=$this->pdo->prepare(
            'SELECT id FROM cms_site_local_jobs WHERE site_id=:site AND job_key=:key'
        );
        $read->execute(['site'=>$this->siteId,'key'=>$jobKey]);
        $id=$read->fetchColumn();
        if(!$id)throw new RuntimeException('tenant_job_not_found');
        return (int)$id;
    }

    public function list(int $actorId):array
    {
        $this->authorize($actorId);
        $q=$this->pdo->prepare(
            'SELECT id,job_key,task_type,is_active,interval_minutes,' .
            'run_as_user_id,next_run_at,last_run_at,last_status,locked_at ' .
            'FROM cms_site_local_jobs WHERE site_id=:site ORDER BY id DESC LIMIT 100'
        );
        $q->execute(['site'=>$this->siteId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Opt-in manual invocation only; not used by run-scheduler.php.
     * A successful run never sends to external destinations.
     */
    public function runDue(int $actorId,int $limit=10):array
    {
        // Phase 8 requires a durable PRE-execution claim and a per-page
        // transaction fence. Missing claim schema refuses work without
        // falling back to the earlier post-execution-only receipts.
        $this->authorize($actorId);
        return (new SiteScopedJobLeaseRunner($this->pdo,$this->siteId))
            ->runDue($actorId,$limit);
    }

    private function authorize(int $actorId):void
    {
        if($actorId<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id ' .
            "WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        if(!in_array($q->fetchColumn(),['owner','admin'],true)){
            throw new RuntimeException('tenant_access_denied');
        }
    }
}
