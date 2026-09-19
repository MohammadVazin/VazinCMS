<?php
declare(strict_types=1);

namespace VazinCMS;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Encrypted, network-agnostic outbox for Business replies.
 *
 * This class never calls Telegram. A dispatcher may claim jobs and deliver
 * them elsewhere, then call markSent/markFailed. Both queueing and claiming
 * are disabled unless an explicit true policy (or callback returning true)
 * was supplied to the constructor.
 */
final class TelegramAssistantOutbox
{
    private bool|Closure|null $policy;

    public function __construct(
        private TelegramAssistantRepository $repository,
        private TelegramBusinessService $businessService,
        bool|callable|null $queuePolicy = null
    ) {
        $this->policy=is_callable($queuePolicy)?Closure::fromCallable($queuePolicy):$queuePolicy;
    }

    public function enabled(array $context=[]): bool
    {
        if($this->policy===true)return true;
        return $this->policy instanceof Closure&&($this->policy)($context)===true;
    }

    /**
     * Queues one reply if policy, rights and Telegram's 24-hour window allow it.
     * Returns null when fail-closed policy denies queueing.
     */
    public function enqueue(array $business,array $conversation,string $action,array $payload,string $dedupeSeed,int $ttlSeconds=3600): ?array
    {
        if(!in_array($action,['send_message','edit_message','chat_action','callback_answer'],true))throw new RuntimeException('عملیات صف دستیار معتبر نیست.');
        $context=['business'=>$business,'conversation'=>$conversation,'action'=>$action];
        if(!$this->enabled($context))return null;
        if(!$this->businessService->canReply($business,(string)($conversation['last_customer_at']??'')))return null;
        $handoffAck=($payload['handoff_ack']??false)===true;
        $operatorReply=($payload['operator_reply']??false)===true;
        $mode=(string)($conversation['mode']??'');
        $modeAllowed=$operatorReply
            ?$mode==='human'
            :($mode==='bot'||($handoffAck&&$mode==='handoff_pending'));
        if(!$modeAllowed)return null;
        $chatId=TelegramAssistantRepository::telegramId((string)$conversation['telegram_chat_id'],true);
        $dedupe=hash('sha256',(int)$business['profile_id'].'|'.(int)$business['id'].'|'.$chatId.'|'.$action.'|'.$dedupeSeed);
        $encrypted=SecretStore::seal(TelegramAssistantRepository::json($payload),self::payloadContext($dedupe));
        $expires=TelegramAssistantRepository::utc('+'.max(30,min(86400,$ttlSeconds)).' seconds');
        $reply=isset($payload['reply_to_message_id'])?(int)$payload['reply_to_message_id']:null;
        return $this->repository->transaction(function()use($business,$conversation,$action,$chatId,$dedupe,$encrypted,$expires,$reply):array{
            $insert=$this->repository->pdo()->prepare('INSERT INTO telegram_assistant_outbox(profile_id,business_connection_row_id,conversation_id,telegram_chat_id,reply_to_message_id,action,payload_encrypted,dedupe_key,expires_at) VALUES(:profile,:business,:conversation,:chat,:reply,:action,:payload,:dedupe,:expires) ON CONFLICT(dedupe_key) DO NOTHING');
            $insert->execute([
                'profile'=>$business['profile_id'],'business'=>$business['id'],'conversation'=>$conversation['id'],'chat'=>$chatId,
                'reply'=>$reply&&$reply>0?$reply:null,'action'=>$action,'payload'=>$encrypted,'dedupe'=>$dedupe,'expires'=>$expires,
            ]);
            $query=$this->repository->pdo()->prepare('SELECT * FROM telegram_assistant_outbox WHERE dedupe_key=:dedupe');$query->execute(['dedupe'=>$dedupe]);$row=$query->fetch();
            return ['created'=>$insert->rowCount()===1,'job'=>(array)$row];
        });
    }

    /**
     * Claims due work without delivering it. Returned rows include decrypted
     * payload and a lock_token required by completion methods.
     */
    public function claim(int $limit=20): array
    {
        $limit=max(1,min(100,$limit));
        if(!$this->enabled(['operation'=>'claim']))return [];
        return $this->repository->transaction(function()use($limit):array{
            // A small requested delivery batch still scans past invalid queue
            // heads. Only valid rows count toward $limit, so claim(1) never
            // strands extra jobs in processing merely to clean stale entries.
            $scanLimit=min(100,max(20,$limit*5));
            $sql="SELECT o.*,b.connection_id,b.business_connection_id,b.business_user_id,b.user_chat_id,b.rights_json,b.authorization_status,b.is_enabled,c.last_customer_at,c.mode,p.is_enabled profile_enabled,tc.is_enabled connection_enabled "
                ."FROM telegram_assistant_outbox o JOIN telegram_business_connections b ON b.id=o.business_connection_row_id JOIN telegram_assistant_conversations c ON c.id=o.conversation_id JOIN telegram_assistant_profiles p ON p.id=o.profile_id JOIN telegram_connections tc ON tc.id=b.connection_id "
                ."WHERE o.status='pending' AND o.next_attempt_at<=CURRENT_TIMESTAMP ORDER BY o.id LIMIT $scanLimit";
            if($this->repository->driver()==='pgsql')$sql.=' FOR UPDATE OF o SKIP LOCKED';
            $rows=$this->repository->pdo()->query($sql)->fetchAll();$claimed=[];
            foreach($rows as$row){
                if(count($claimed)>=$limit)break;
                if(TelegramAssistantRepository::timestamp((string)$row['expires_at'])<=time()
                    ||(int)$row['profile_enabled']!==1
                    ||(int)$row['connection_enabled']!==1
                    ||!$this->businessService->canReply($row,(string)$row['last_customer_at'])){
                    $this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status='cancelled',last_error='reply_window_or_policy_closed',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='pending'")
                        ->execute(['id'=>$row['id']]);
                    continue;
                }
                $token=bin2hex(random_bytes(24));
                $lock=$this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status='processing',attempts=attempts+1,locked_at=CURRENT_TIMESTAMP,lock_token=:token,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='pending'");
                $lock->execute(['token'=>$token,'id'=>$row['id']]);if($lock->rowCount()!==1)continue;
                try{$payload=json_decode(SecretStore::open((string)$row['payload_encrypted'],self::payloadContext((string)$row['dedupe_key'])),true,64,JSON_THROW_ON_ERROR);}
                catch(Throwable $error){
                    $this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status='failed',locked_at=NULL,lock_token=NULL,last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND lock_token=:token")
                        ->execute(['error'=>'encrypted_payload_invalid','id'=>$row['id'],'token'=>$token]);
                    continue;
                }
                $handoffAck=is_array($payload)&&($payload['handoff_ack']??false)===true;
                $operatorReply=is_array($payload)&&($payload['operator_reply']??false)===true;
                $mode=(string)$row['mode'];
                $modeAllowed=$operatorReply
                    ?$mode==='human'
                    :($mode==='bot'||($handoffAck&&$mode==='handoff_pending'));
                if(!$modeAllowed){
                    $this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status='cancelled',locked_at=NULL,lock_token=NULL,last_error='conversation_handed_off',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND lock_token=:token")
                        ->execute(['id'=>$row['id'],'token'=>$token]);
                    continue;
                }
                $claimed[]=[
                    'id'=>(int)$row['id'],'lock_token'=>$token,'action'=>(string)$row['action'],'payload'=>is_array($payload)?$payload:[],
                    'connection_id'=>(int)$row['connection_id'],'business_connection_id'=>(string)$row['business_connection_id'],
                    'chat_id'=>(string)$row['telegram_chat_id'],'reply_to_message_id'=>$row['reply_to_message_id']!==null?(int)$row['reply_to_message_id']:null,
                    'attempt'=>(int)$row['attempts']+1,'expires_at'=>(string)$row['expires_at'],
                ];
            }
            return $claimed;
        });
    }

    /** True when another bounded claim pass can still make progress. */
    public function hasDuePending(): bool
    {
        if(!$this->enabled(['operation'=>'claim']))return false;
        $query=$this->repository->pdo()->query("SELECT 1 FROM telegram_assistant_outbox WHERE status='pending' AND next_attempt_at<=CURRENT_TIMESTAMP LIMIT 1");
        return(bool)$query->fetchColumn();
    }

    /**
     * Revalidates a claimed job under database locks and keeps those locks
     * through the external delivery. Disable/release mutations therefore win
     * before the send or wait until that send has completed; a stale claim can
     * never be delivered after the mutation has returned.
     *
     * @return array{status:string,response?:array}
     */
    public function deliverClaimed(int $jobId,string $lockToken,callable $delivery): array
    {
        if($jobId<1)throw new RuntimeException('شناسهٔ صف معتبر نیست.');
        self::lockToken($lockToken);
        return $this->repository->transaction(function()use($jobId,$lockToken,$delivery):array{
            $lookup=$this->repository->pdo()->prepare(
                'SELECT o.profile_id,o.business_connection_row_id,o.conversation_id,b.connection_id '
                .'FROM telegram_assistant_outbox o JOIN telegram_business_connections b ON b.id=o.business_connection_row_id WHERE o.id=:id'
            );
            $lookup->execute(['id'=>$jobId]);$ids=$lookup->fetch();
            if(!is_array($ids))return ['status'=>'lost'];

            // Keep the same order used by profile/business mutations.
            $this->lockDeliveryRow('telegram_assistant_profiles',(int)$ids['profile_id']);
            $this->lockDeliveryRow('telegram_connections',(int)$ids['connection_id']);
            $this->lockDeliveryRow('telegram_business_connections',(int)$ids['business_connection_row_id']);
            $this->lockDeliveryRow('telegram_assistant_conversations',(int)$ids['conversation_id']);
            $this->lockDeliveryRow('telegram_assistant_outbox',$jobId);

            $query=$this->repository->pdo()->prepare(
                "SELECT o.*,b.connection_id,b.business_connection_id,b.business_user_id,b.user_chat_id,b.rights_json,b.authorization_status,b.is_enabled,"
                ."c.last_customer_at,c.mode,p.is_enabled profile_enabled,tc.is_enabled connection_enabled "
                ."FROM telegram_assistant_outbox o JOIN telegram_business_connections b ON b.id=o.business_connection_row_id "
                ."JOIN telegram_assistant_conversations c ON c.id=o.conversation_id JOIN telegram_assistant_profiles p ON p.id=o.profile_id "
                ."JOIN telegram_connections tc ON tc.id=b.connection_id WHERE o.id=:id AND o.status='processing' AND o.lock_token=:token"
            );
            $query->execute(['id'=>$jobId,'token'=>$lockToken]);$row=$query->fetch();
            if(!is_array($row))return ['status'=>'lost'];

            $payload=json_decode(SecretStore::open((string)$row['payload_encrypted'],self::payloadContext((string)$row['dedupe_key'])),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new RuntimeException('محتوای صف معتبر نیست.');
            $handoffAck=($payload['handoff_ack']??false)===true;$operatorReply=($payload['operator_reply']??false)===true;$mode=(string)$row['mode'];
            $modeAllowed=$operatorReply?$mode==='human':($mode==='bot'||($handoffAck&&$mode==='handoff_pending'));
            $policyOpen=TelegramAssistantRepository::timestamp((string)$row['expires_at'])>time()
                &&(int)$row['profile_enabled']===1&&(int)$row['connection_enabled']===1
                &&$this->businessService->canReply($row,(string)$row['last_customer_at'])&&$modeAllowed;
            if(!$policyOpen){
                $this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status='cancelled',locked_at=NULL,lock_token=NULL,last_error='delivery_policy_revoked',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='processing' AND lock_token=:token")
                    ->execute(['id'=>$jobId,'token'=>$lockToken]);
                return ['status'=>'cancelled'];
            }

            $job=[
                'id'=>$jobId,'lock_token'=>$lockToken,'action'=>(string)$row['action'],'payload'=>$payload,
                'connection_id'=>(int)$row['connection_id'],'business_connection_id'=>(string)$row['business_connection_id'],
                'chat_id'=>(string)$row['telegram_chat_id'],'reply_to_message_id'=>$row['reply_to_message_id']!==null?(int)$row['reply_to_message_id']:null,
                'attempt'=>(int)$row['attempts'],'expires_at'=>(string)$row['expires_at'],
            ];
            $response=$delivery($job);
            if(!is_array($response))throw new RuntimeException('پاسخ انتقال Telegram معتبر نیست.');
            $messageId=filter_var($response['message_id']??null,FILTER_VALIDATE_INT);
            if(!$this->markSent($jobId,$lockToken,is_int($messageId)?$messageId:null))throw new RuntimeException('ثبت تحویل صف ناموفق بود.');
            return ['status'=>'sent','response'=>$response];
        });
    }

    public function markSent(int $jobId,string $lockToken,?int $telegramMessageId=null): bool
    {
        self::lockToken($lockToken);
        $statement=$this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status='sent',telegram_message_id=:message,locked_at=NULL,lock_token=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='processing' AND lock_token=:token");
        $statement->execute(['message'=>$telegramMessageId&&$telegramMessageId>0?$telegramMessageId:null,'id'=>$jobId,'token'=>$lockToken]);
        return $statement->rowCount()===1;
    }

    /** Reschedules using Telegram retry_after when present; terminal after max_attempts. */
    public function markFailed(int $jobId,string $lockToken,string $error,?int $retryAfter=null): bool
    {
        self::lockToken($lockToken);$retryAfter=max(1,min(86400,$retryAfter??60));
        return $this->repository->transaction(function()use($jobId,$lockToken,$error,$retryAfter):bool{
            $query=$this->repository->pdo()->prepare('SELECT attempts,max_attempts,expires_at FROM telegram_assistant_outbox WHERE id=:id AND status=\'processing\' AND lock_token=:token');
            $query->execute(['id'=>$jobId,'token'=>$lockToken]);$row=$query->fetch();if(!is_array($row))return false;
            $terminal=(int)$row['attempts']>=(int)$row['max_attempts']||TelegramAssistantRepository::timestamp((string)$row['expires_at'])<=time()+$retryAfter;
            $statement=$this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status=:status,next_attempt_at=:next,locked_at=NULL,lock_token=NULL,last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND lock_token=:token");
            $statement->execute(['status'=>$terminal?'failed':'pending','next'=>TelegramAssistantRepository::utc('+'.$retryAfter.' seconds'),'error'=>mb_substr($error,0,1000),'id'=>$jobId,'token'=>$lockToken]);
            return $statement->rowCount()===1;
        });
    }

    /** Releases workers that died while holding a job. */
    public function releaseStale(int $staleSeconds=300): int
    {
        $before=TelegramAssistantRepository::utc('-'.max(30,min(86400,$staleSeconds)).' seconds');
        $statement=$this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status=CASE WHEN attempts>=max_attempts THEN 'failed' ELSE 'pending' END,locked_at=NULL,lock_token=NULL,last_error='stale_worker_lock',updated_at=CURRENT_TIMESTAMP WHERE status='processing' AND locked_at<:before");
        $statement->execute(['before'=>$before]);return $statement->rowCount();
    }

    private function lockDeliveryRow(string $table,int $id): void
    {
        if($this->repository->driver()!=='pgsql')return;
        $allowed=['telegram_assistant_profiles','telegram_connections','telegram_business_connections','telegram_assistant_conversations','telegram_assistant_outbox'];
        if(!in_array($table,$allowed,true)||$id<1)throw new RuntimeException('قفل تحویل معتبر نیست.');
        $query=$this->repository->pdo()->prepare('SELECT id FROM '.$table.' WHERE id=:id FOR UPDATE');$query->execute(['id'=>$id]);
        if(!$query->fetchColumn())throw new RuntimeException('رکورد تحویل پیدا نشد.');
    }

    private static function payloadContext(string $dedupe): string{return 'telegram.assistant-outbox.'.$dedupe;}
    private static function lockToken(string $token): void{if(preg_match('/^[a-f0-9]{48}$/',$token)!==1)throw new RuntimeException('قفل صف معتبر نیست.');}
}
