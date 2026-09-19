<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO; use RuntimeException; use Throwable;

final class Scheduler
{
    public static function types():array{return ['webhook_queue'=>'پردازش صف Webhook','notification_queue'=>'پردازش اعلان‌ها','service_expiry'=>'بررسی سررسید سرویس‌ها','audit_cleanup'=>'پاک‌سازی رویدادهای قدیمی','content_publish'=>'انتشار محتوای زمان‌بندی‌شده','trust_scan'=>'اسکن اعتماد و Advisory بسته‌ها','update_feed'=>'بررسی فید رسمی به‌روزرسانی'];}
    public static function runDue(?int $onlyId=null):array
    {
        $pdo=Database::connection();$where=$onlyId?'id=:id':"is_active=1 AND next_run_at<=CURRENT_TIMESTAMP";$s=$pdo->prepare('SELECT * FROM scheduled_tasks WHERE '.$where.' ORDER BY next_run_at ASC LIMIT 25');$s->execute($onlyId?['id'=>$onlyId]:[]);$result=[];
        foreach($s->fetchAll() as $task)$result[]=self::run($pdo,$task,$onlyId!==null);return $result;
    }
    private static function run(PDO $pdo,array $task,bool $manual):array
    {
        $token=bin2hex(random_bytes(16));$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$stale=$driver==='pgsql'?"CURRENT_TIMESTAMP-INTERVAL '15 minutes'":"datetime('now','-15 minutes')";
        $lock=$pdo->prepare("UPDATE scheduled_tasks SET locked_at=CURRENT_TIMESTAMP,lock_token=:token WHERE id=:id AND (locked_at IS NULL OR locked_at<$stale)");$lock->execute(['token'=>$token,'id'=>$task['id']]);if($lock->rowCount()!==1)return ['id'=>(int)$task['id'],'status'=>'skipped'];
        $pdo->prepare("INSERT INTO scheduled_task_runs(task_id,run_token,status) VALUES(:id,:token,'running')")->execute(['id'=>$task['id'],'token'=>$token]);$runId=(int)$pdo->lastInsertId();$start=microtime(true);
        try{$message=self::execute($pdo,(string)$task['task_type'],json_decode((string)$task['config'],true)?:[]);$status='succeeded';}
        catch(Throwable $e){$message=mb_substr($e->getMessage(),0,1000);$status='failed';}
        $duration=(int)round((microtime(true)-$start)*1000);$minutes=max(1,(int)$task['interval_minutes']);$next=$manual?(string)$task['next_run_at']:date('Y-m-d H:i:s',time()+$minutes*60);
        $pdo->prepare('UPDATE scheduled_task_runs SET status=:status,message=:message,finished_at=CURRENT_TIMESTAMP,duration_ms=:duration WHERE id=:id')->execute(compact('status','message','duration')+['id'=>$runId]);
        $pdo->prepare('UPDATE scheduled_tasks SET last_run_at=CURRENT_TIMESTAMP,last_status=:status,next_run_at=:next,locked_at=NULL,lock_token=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND lock_token=:token')->execute(['status'=>$status,'next'=>$next,'id'=>$task['id'],'token'=>$token]);
        return ['id'=>(int)$task['id'],'status'=>$status,'message'=>$message];
    }
    private static function execute(PDO $pdo,string $type,array $config):string
    {
        return match($type){
            'webhook_queue'=>self::command(dirname(__DIR__).'/scripts/process-webhooks.php'),
            'notification_queue'=>self::notifications($pdo),
            'service_expiry'=>self::expiry($pdo),
            'audit_cleanup'=>self::cleanup($pdo,max(30,min(3650,(int)($config['retention_days']??365)))),
            'content_publish'=>self::publishScheduled($pdo),
            'trust_scan'=>json_encode(TrustScanner::run($pdo),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'update_feed'=>json_encode(UpdateFeedService::refresh($pdo),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            default=>throw new RuntimeException('نوع وظیفه پشتیبانی نمی‌شود.'),
        };
    }
    private static function command(string $script):string{$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1';exec($cmd,$out,$code);if($code!==0)throw new RuntimeException(implode("\n",$out)?:'اجرای پردازشگر ناموفق بود.');return mb_substr(implode("\n",$out),0,1000);}
    private static function notifications(PDO $pdo):string{$count=$pdo->exec("UPDATE notifications SET status='queued',updated_at=CURRENT_TIMESTAMP WHERE status='pending' AND channel<>'admin'");return "$count اعلان وارد صف شد.";}
    private static function expiry(PDO $pdo):string{$count=$pdo->exec("UPDATE services SET status='expired',updated_at=CURRENT_TIMESTAMP WHERE status='active' AND expires_at IS NOT NULL AND expires_at<CURRENT_TIMESTAMP");return "$count سرویس منقضی علامت‌گذاری شد.";}

    private static function publishScheduled(PDO $pdo):string
    {
        $ids=EditorialWorkflow::dueScheduled($pdo);$count=0;
        foreach($ids as$id){$pdo->prepare("UPDATE cms_pages SET editorial_state='published',status='published',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND editorial_state='scheduled'")->execute(['id'=>$id]);SearchIndex::refreshPage($pdo,(int)$id);$count++;}
        return $count.' محتوای زمان‌بندی‌شده منتشر شد.';
    }

    private static function cleanup(PDO $pdo,int $days):string{$cutoff=date('Y-m-d H:i:s',time()-$days*86400);$s=$pdo->prepare('DELETE FROM audit_logs WHERE created_at<:cutoff');$s->execute(['cutoff'=>$cutoff]);return $s->rowCount().' رویداد قدیمی پاک شد.';}
}
