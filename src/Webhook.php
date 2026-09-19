<?php
declare(strict_types=1);
namespace VazinCMS;
use RuntimeException;

final class Webhook
{
    public static function events():array{return ['webhook.test','customer.created','invoice.created','invoice.paid','payment.confirmed','service.activated','service.expiring','service.expired','wallet.credited','wallet.debited'];}
    public static function validateUrl(string $url):void
    {
        $parts=parse_url($url);if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('نشانی Webhook باید HTTPS عمومی و معتبر باشد.');
        $host=strtolower((string)$parts['host']);if($host==='localhost'||str_ends_with($host,'.local'))throw new RuntimeException('مقصد محلی مجاز نیست.');
        $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:(gethostbynamel($host)?:[]);if($ips===[])throw new RuntimeException('دامنه Webhook قابل شناسایی نیست.');foreach($ips as $ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('مقصد شبکه خصوصی یا رزروشده مجاز نیست.');}
    }
    public static function protect(string $secret):string
    {
        $key=hash('sha256',(string)getenv('APP_KEY'),true);$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('رمزگذاری راز Webhook ناموفق بود.');return base64_encode($iv.$tag.$cipher);
    }
    public static function reveal(string $protected):string
    {
        $raw=base64_decode($protected,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('راز Webhook قابل بازیابی نیست.');$key=hash('sha256',(string)getenv('APP_KEY'),true);$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($plain===false)throw new RuntimeException('راز Webhook قابل بازیابی نیست.');return $plain;
    }
    public static function enqueue(string $event,array $data,?int $endpointId=null):int
    {
        DeliveryPolicy::assertExternalEnabled();
        if(!in_array($event,self::events(),true))throw new RuntimeException('نوع رویداد Webhook معتبر نیست.');$pdo=Database::connection();$sql='SELECT id,events FROM webhook_endpoints WHERE is_active=1'.($endpointId?' AND id=:id':'');$s=$pdo->prepare($sql);$s->execute($endpointId?['id'=>$endpointId]:[]);$count=0;
        foreach($s->fetchAll() as $endpoint){$events=json_decode((string)$endpoint['events'],true)?:[];if(!in_array($event,$events,true))continue;$eventId='evt_'.bin2hex(random_bytes(12));$payload=json_encode(['id'=>$eventId,'type'=>$event,'created_at'=>gmdate('c'),'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$q=$pdo->prepare("INSERT INTO webhook_deliveries(endpoint_id,event_id,event_type,payload,status,next_attempt_at) VALUES(:endpoint,:event_id,:event_type,:payload,'pending',CURRENT_TIMESTAMP)");$q->execute(['endpoint'=>$endpoint['id'],'event_id'=>$eventId,'event_type'=>$event,'payload'=>$payload]);$count++;}return $count;
    }
    public static function retry(int $deliveryId):void
    {
        DeliveryPolicy::assertExternalEnabled();
        $pdo=Database::connection();$s=$pdo->prepare("UPDATE webhook_deliveries SET status='pending',next_attempt_at=CURRENT_TIMESTAMP,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status IN ('failed','dead')");$s->execute(['id'=>$deliveryId]);if($s->rowCount()!==1)throw new RuntimeException('این ارسال قابل تلاش مجدد نیست.');
    }
    public static function signature(string $payload,string $secret,int $timestamp):string{return 't='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$payload,$secret);}
}
