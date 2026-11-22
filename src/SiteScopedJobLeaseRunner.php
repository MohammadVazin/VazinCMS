<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Phase 8 prototype: durable claim BEFORE any local page changes.
 *
 * Design:
 * - No automatic cron scheduling, no external delivery, no network.
 * - The claim and job lock are inserted in ONE DB transaction.
 * - Each page mutation is fenced by a conditional write against the live
 *   job lock/claim INSIDE the same transaction as the page mutation.
 * - Completed job, claim, and legacy receipt are finalized atomically.
 * - Unexpected failure/crash => persistent claim/lock. Quarantine and
 *   closing the claim require explicit operator review; never auto-retry.
 *
 * This is "crash detectable, fail closed", NOT guaranteed exactly-once.
 */
final class SiteScopedJobLeaseRunner
{
    private readonly SiteScopedEditorialWorkflow $workflow;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ) {
        if($siteId<1 || !SiteScopeMode::active($pdo)){
            throw new RuntimeException('tenant_job_claim_schema_unavailable');
        }
        $site=$pdo->prepare(
            "SELECT 1 FROM cms_sites WHERE id=:site AND status IN('active','staging')"
        );
        $site->execute(['site'=>$siteId]);
        if(!$site->fetchColumn())throw new RuntimeException('tenant_site_unavailable');
        try {
            $pdo->query('SELECT site_id,job_id,claim_token,state,claimed_at,review_note ' .
                'FROM cms_site_job_claims WHERE 1=0');
        } catch(Throwable) {
            throw new RuntimeException('tenant_job_claim_schema_unavailable');
        }
        $this->workflow=new SiteScopedEditorialWorkflow($pdo,$siteId);
    }

    /**
     * Claim a due job for a specific manager. This commits a durable
     * pre-execution receipt. Designed to allow a test process to exit after
     * claiming and then verify cross-process recovery without any writes.
     */
    public function claimNext(int $actorId,?int $onlyJobId=null):?array
    {
        $this->authorize($actorId);
        if($onlyJobId!==null && $onlyJobId<1){
            throw new RuntimeException('tenant_claim_job_invalid');
        }
        $sql="SELECT id,interval_minutes FROM cms_site_local_jobs " .
            "WHERE site_id=:site AND run_as_user_id=:actor " .
            "AND task_type='publish_due_local' AND is_active=TRUE " .
            "AND next_run_at<=CURRENT_TIMESTAMP AND locked_at IS NULL " .
            "AND lock_token IS NULL";
        $params=['site'=>$this->siteId,'actor'=>$actorId];
        if($onlyJobId!==null){
            $sql.=' AND id=:job';
            $params['job']=$onlyJobId;
        }
        $sql.=' ORDER BY next_run_at,id LIMIT 1';
        $q=$this->pdo->prepare($sql);
        $q->execute($params);
        $record=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($record))return null;
        $jobId=(int)$record['id'];
        $token=bin2hex(random_bytes(16));
        $this->begin();
        try{
            $lock=$this->pdo->prepare(
                'UPDATE cms_site_local_jobs SET locked_at=CURRENT_TIMESTAMP,lock_token=:token ' .
                'WHERE id=:id AND site_id=:site AND run_as_user_id=:actor ' .
                "AND is_active=TRUE AND task_type='publish_due_local' " .
                'AND next_run_at<=CURRENT_TIMESTAMP AND locked_at IS NULL ' .
                'AND lock_token IS NULL'
            );
            $lock->execute([
                'token'=>$token,'id'=>$jobId,'site'=>$this->siteId,'actor'=>$actorId
            ]);
            if($lock->rowCount()!==1) {
                $this->pdo->rollBack();
                return null;
            }
            $claim=$this->pdo->prepare(
                "INSERT INTO cms_site_job_claims(site_id,job_id,actor_id,claim_token,state) " .
                "VALUES(:site,:job,:actor,:token,'claimed')"
            );
            $claim->execute([
                'site'=>$this->siteId,'job'=>$jobId,'actor'=>$actorId,'token'=>$token
            ]);
            $this->pdo->commit();
            return ['id'=>$jobId,'site_id'=>$this->siteId,
                    'actor_id'=>$actorId,'token'=>$token,
                    'interval_minutes'=>(int)$record['interval_minutes']];
        }catch(Throwable $error){
            $this->rollback();
            throw $error;
        }
    }

    /**
     * Perform previously claimed local work. Fencing is rechecked both
     * before execution and inside EVERY page mutation transaction.
     */
    public function executeClaim(
        int $actorId,array $claim,?callable $authorizationFence=null
    ):array
    {
        $this->authorize($actorId);
        $jobId=(int)($claim['id']??0);
        $token=(string)($claim['token']??'');
        if($jobId<1 || !preg_match('/^[a-f0-9]{32}$/D',$token) ||
           (int)($claim['site_id']??0)!==$this->siteId ||
           (int)($claim['actor_id']??0)!==$actorId){
            throw new RuntimeException('tenant_claim_invalid');
        }
        $this->checkClaim($actorId,$jobId,$token);
        $details=['published'=>0,'external_delivery'=>false];
        try{
            $published=$this->workflow->publishDue(
                $actorId,100,
                function()use($actorId,$jobId,$token,$authorizationFence):void {
                    // Revalidate the worker capability before every
                    // individual page mutation, in the SAME transaction.
                    if($authorizationFence!==null)$authorizationFence();
                    $this->fence($actorId,$jobId,$token);
                }
            );
            $details['published']=(int)($published['published']??0);
        }catch(Throwable $error){
            // The page may have been committed before an interruption. Never
            // claim zero writes or automatically retry. Retain the lease and
            // quarantine the job for an operator's content/log inspection.
            $this->markReviewRequired($actorId,$jobId,$token);
            return ['id'=>$jobId,'status'=>'review_required',
                    'operator_review_required'=>true,
                    'external_delivery'=>false];
        }
        $this->finalize($actorId,$jobId,$token,$details,
            (int)($claim['interval_minutes']??0));
        return ['id'=>$jobId,'status'=>'succeeded']+$details;
    }

    public function runDue(int $actorId,int $limit=10):array
    {
        $this->authorize($actorId);
        $limit=max(1,min(25,$limit));
        $results=[];
        for($i=0;$i<$limit;$i++){
            $claim=$this->claimNext($actorId);
            if($claim===null)break;
            $results[]=$this->executeClaim($actorId,$claim);
            if(($results[array_key_last($results)]['status']??'')!=='succeeded'){
                break;
            }
        }
        return $results;
    }

    /**
     * Read-only inspection. A claim is evidence that a worker MAY have
     * published before dying. Even an old claim must not auto-requeue.
     */
    public function inspect(int $actorId):array
    {
        $this->authorize($actorId);
        $q=$this->pdo->prepare(
            "SELECT c.job_id,c.claim_token,c.state,c.claimed_at,c.finished_at," .
            "j.is_active,j.locked_at,j.last_status " .
            "FROM cms_site_job_claims c " .
            "JOIN cms_site_local_jobs j ON j.site_id=c.site_id AND j.id=c.job_id " .
            "WHERE c.site_id=:site AND c.state IN('claimed','review_required') " .
            "ORDER BY c.id DESC LIMIT 100"
        );
        $q->execute(['site'=>$this->siteId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Operator-triggered quarantine for stale claims (>=15 minutes).
     * Does not release the lease, re-enable the job or re-run publication.
     */
    public function quarantineStale(int $actorId,int $jobId,string $token):void
    {
        $this->authorize($actorId);
        if($jobId<1 || !preg_match('/^[a-f0-9]{32}$/D',$token)){
            throw new RuntimeException('tenant_claim_invalid');
        }
        $this->begin();
        try{
            $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $cutoff=$driver==='pgsql'
                ? "CURRENT_TIMESTAMP-INTERVAL '15 minutes'"
                : "datetime('now','-15 minutes')";
            $q=$this->pdo->prepare(
                "UPDATE cms_site_local_jobs SET is_active=FALSE,last_status='review_required'," .
                'updated_at=CURRENT_TIMESTAMP ' .
                'WHERE id=:id AND site_id=:site AND lock_token=:token ' .
                'AND locked_at IS NOT NULL AND locked_at<='.$cutoff
            );
            $q->execute(['site'=>$this->siteId,'id'=>$jobId,'token'=>$token]);
            if($q->rowCount()!==1){
                throw new RuntimeException('tenant_claim_not_stale_or_owned');
            }
            $q=$this->pdo->prepare(
                "UPDATE cms_site_job_claims SET state='review_required'," .
                "result_json='{\"outcome\":\"unknown\",\"operator_review_required\":true}'," .
                'finished_at=CURRENT_TIMESTAMP ' .
                "WHERE site_id=:site AND job_id=:job AND claim_token=:token AND state='claimed'"
            );
            $q->execute(['site'=>$this->siteId,'job'=>$jobId,'token'=>$token]);
            if($q->rowCount()!==1){
                throw new RuntimeException('tenant_claim_missing_or_completed');
            }
            $this->pdo->commit();
        }catch(Throwable $e){
            $this->rollback();throw $e;
        }
    }

    /**
     * Explicit signed-off review. A boolean attestation is only operator
     * input, NOT OS proof. The job stays disabled after closing the lease.
     */
    public function closeReviewed(
        int $actorId,int $jobId,string $token,string $note,bool $workerStopped
    ):void {
        $this->authorize($actorId);
        if(!$workerStopped || mb_strlen(trim($note))<20 ||
           mb_strlen($note)>2000 || $jobId<1 ||
           !preg_match('/^[a-f0-9]{32}$/D',$token)){
            throw new RuntimeException('tenant_review_attestation_required');
        }
        $this->begin();
        try{
            $q=$this->pdo->prepare(
                "UPDATE cms_site_job_claims SET state='closed'," .
                'review_note=:note,reviewed_by=:actor,reviewed_at=CURRENT_TIMESTAMP ' .
                "WHERE site_id=:site AND job_id=:job AND claim_token=:token " .
                "AND state='review_required'"
            );
            $q->execute([
                'note'=>trim($note),'actor'=>$actorId,'site'=>$this->siteId,
                'job'=>$jobId,'token'=>$token
            ]);
            if($q->rowCount()!==1)throw new RuntimeException('tenant_review_claim_missing');
            $q=$this->pdo->prepare(
                "UPDATE cms_site_local_jobs SET lock_token=NULL,locked_at=NULL," .
                "is_active=FALSE,last_status='interrupted_reviewed',updated_at=CURRENT_TIMESTAMP " .
                'WHERE site_id=:site AND id=:job AND lock_token=:token AND is_active=FALSE'
            );
            $q->execute(['site'=>$this->siteId,'job'=>$jobId,'token'=>$token]);
            if($q->rowCount()!==1)throw new RuntimeException('tenant_review_lock_lost');
            $this->pdo->commit();
        }catch(Throwable $e){
            $this->rollback();throw $e;
        }
    }

    private function checkClaim(int $actor,int $job,string $token):void
    {
        $q=$this->pdo->prepare(
            'SELECT 1 FROM cms_site_local_jobs j ' .
            'JOIN cms_site_job_claims c ON c.job_id=j.id AND c.site_id=j.site_id ' .
            'WHERE j.id=:job AND j.site_id=:site AND j.lock_token=:token ' .
            'AND j.locked_at IS NOT NULL AND j.is_active=TRUE AND j.run_as_user_id=:actor ' .
            "AND c.claim_token=:same_token AND c.actor_id=:same_actor AND c.state='claimed' " .
            'LIMIT 1'
        );
        $q->execute([
            'job'=>$job,'site'=>$this->siteId,'token'=>$token,
            'actor'=>$actor,'same_token'=>$token,'same_actor'=>$actor
        ]);
        if(!$q->fetchColumn())throw new RuntimeException('tenant_claim_not_active');
    }

    private function fence(int $actor,int $job,string $token):void
    {
        if(!$this->pdo->inTransaction()){
            throw new RuntimeException('tenant_claim_fence_needs_transaction');
        }
        // A real conditional UPDATE to the job row obtains a writer lock
        // inside the SAME transaction as the page mutation. A concurrent
        // operator quarantine/recovery cannot race past this fence.
        $q=$this->pdo->prepare(
            'UPDATE cms_site_local_jobs SET locked_at=locked_at ' .
            'WHERE id=:job AND site_id=:site AND lock_token=:token ' .
            'AND locked_at IS NOT NULL AND is_active=TRUE ' .
            'AND run_as_user_id=:actor AND EXISTS(' .
            'SELECT 1 FROM cms_site_job_claims c ' .
            'WHERE c.site_id=cms_site_local_jobs.site_id ' .
            'AND c.job_id=cms_site_local_jobs.id AND c.claim_token=:same_token ' .
            "AND c.state='claimed' AND c.actor_id=:same_actor)"
        );
        $q->execute([
            'job'=>$job,'site'=>$this->siteId,'token'=>$token,
            'actor'=>$actor,'same_token'=>$token,'same_actor'=>$actor
        ]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_claim_fence_lost');
    }

    private function finalize(
        int $actor,int $job,string $token,array $result,int $intervalMinutes
    ):void {
        if($intervalMinutes<5 || $intervalMinutes>525600){
            throw new RuntimeException('tenant_claim_invalid_interval');
        }
        $encoded=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $this->begin();
        try{
            $q=$this->pdo->prepare(
                "UPDATE cms_site_job_claims SET state='succeeded'," .
                'result_json=:result,finished_at=CURRENT_TIMESTAMP ' .
                'WHERE site_id=:site AND job_id=:job AND actor_id=:actor ' .
                "AND claim_token=:token AND state='claimed'"
            );
            $q->execute([
                'result'=>$encoded,'site'=>$this->siteId,
                'job'=>$job,'actor'=>$actor,'token'=>$token
            ]);
            if($q->rowCount()!==1)throw new RuntimeException('tenant_claim_finalize_lost');
            $q=$this->pdo->prepare(
                "UPDATE cms_site_local_jobs SET last_run_at=CURRENT_TIMESTAMP," .
                "last_status='succeeded',next_run_at=:next," .
                'locked_at=NULL,lock_token=NULL,updated_at=CURRENT_TIMESTAMP ' .
                'WHERE id=:job AND site_id=:site AND run_as_user_id=:actor ' .
                'AND lock_token=:token AND is_active=TRUE'
            );
            $q->execute([
                'next'=>gmdate('Y-m-d H:i:s',time()+$intervalMinutes*60),
                'job'=>$job,'site'=>$this->siteId,
                'actor'=>$actor,'token'=>$token
            ]);
            if($q->rowCount()!==1)throw new RuntimeException('tenant_claim_lock_lost');
            $receipt=$this->pdo->prepare(
                "INSERT INTO cms_site_local_job_runs(site_id,job_id,actor_id,run_token," .
                "status,details_json) VALUES(:site,:job,:actor,:token,'succeeded',:details)"
            );
            $receipt->execute([
                'site'=>$this->siteId,'job'=>$job,'actor'=>$actor,
                'token'=>$token,'details'=>$encoded
            ]);
            $this->pdo->commit();
        }catch(Throwable $error){
            $this->rollback();
            throw $error;
        }
    }

    private function markReviewRequired(int $actor,int $job,string $token):void
    {
        $this->begin();
        try{
            $q=$this->pdo->prepare(
                "UPDATE cms_site_job_claims SET state='review_required'," .
                "result_json='{\"outcome\":\"unknown\",\"operator_review_required\":true}'," .
                'finished_at=CURRENT_TIMESTAMP WHERE site_id=:site AND job_id=:job ' .
                "AND actor_id=:actor AND claim_token=:token AND state='claimed'"
            );
            $q->execute([
                'site'=>$this->siteId,'job'=>$job,
                'actor'=>$actor,'token'=>$token
            ]);
            if($q->rowCount()!==1)throw new RuntimeException('tenant_claim_review_failed');
            $q=$this->pdo->prepare(
                "UPDATE cms_site_local_jobs SET is_active=FALSE,last_status='review_required'," .
                'updated_at=CURRENT_TIMESTAMP WHERE site_id=:site AND id=:job ' .
                'AND run_as_user_id=:actor AND lock_token=:token'
            );
            $q->execute([
                'site'=>$this->siteId,'job'=>$job,'actor'=>$actor,'token'=>$token
            ]);
            if($q->rowCount()!==1)throw new RuntimeException('tenant_claim_review_lock_lost');
            $this->pdo->commit();
        }catch(Throwable $error){
            $this->rollback();
            throw $error;
        }
    }

    private function authorize(int $actor):void
    {
        if($actor<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id ' .
            'WHERE m.site_id=:site AND m.user_id=:actor ' .
            "AND u.status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'actor'=>$actor]);
        if(!in_array($q->fetchColumn(),['owner','admin'],true)){
            throw new RuntimeException('tenant_access_denied');
        }
    }

    private function begin():void
    {
        if($this->pdo->inTransaction()){
            throw new RuntimeException('tenant_claim_ambient_transaction');
        }
        if(!$this->pdo->beginTransaction()){
            throw new RuntimeException('tenant_claim_transaction_failed');
        }
    }

    private function rollback():void
    {
        if($this->pdo->inTransaction())$this->pdo->rollBack();
    }
}
