<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

final class ConnectorRuntime
{
    public static function request(PDO $pdo,int $connectorId,string $method,string $path,array $options=[]): array
    {
        $q=$pdo->prepare('SELECT * FROM service_connectors WHERE id=:id');$q->execute(['id'=>$connectorId]);$c=$q->fetch();if(!$c)throw new RuntimeException('Connector not found.');
        $base=rtrim((string)$c['base_url'],'/');if($base===''||!str_starts_with($base,'https://'))throw new RuntimeException('Connector base URL unavailable.');
        if(!preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#',$path))throw new RuntimeException('Invalid connector path.');
        $url=$base.$path;$headers=['Accept: application/json'];$body=$options['json']??null;
        if($body!==null){$headers[]='Content-Type: application/json';$payload=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}else{$payload=null;}
        if(!empty($options['idempotency_key']))$headers[]='Idempotency-Key: '.preg_replace('/[^A-Za-z0-9._:-]/','',(string)$options['idempotency_key']);
        if(!empty($options['tenant']))$headers[]='X-Vazin-Tenant: '.preg_replace('/[^A-Za-z0-9._:-]/','',(string)$options['tenant']);
        $creds=ConnectorRegistry::credentials($pdo,$connectorId);if(isset($creds['bearer'])&&is_string($creds['bearer']))$headers[]='Authorization: Bearer '.$creds['bearer'];
        return self::send($pdo,$connectorId,$method,$url,$headers,$payload,(int)($options['retries']??2));
    }
    private static function send(PDO $pdo,int $connectorId,string $method,string $url,array $headers,?string $payload,int $retries): array
    {
        $attempt=0;$last='';
        do{$attempt++;$start=microtime(true);$h=curl_init($url);curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false]);if($payload!==null)curl_setopt($h,CURLOPT_POSTFIELDS,$payload);$response=(string)curl_exec($h);$http=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$err=curl_error($h);curl_close($h);$latency=(int)round((microtime(true)-$start)*1000);
            if($err===''&&$http>=200&&$http<300){self::recordHealth($pdo,$connectorId,true,$http,null,null,$latency);$decoded=json_decode($response,true);return['ok'=>true,'status'=>$http,'data'=>is_array($decoded)?$decoded:$response,'attempts'=>$attempt,'latency_ms'=>$latency];}
            $last=$err!==''?$err:'HTTP '.$http;self::recordHealth($pdo,$connectorId,false,$http,'upstream_error',mb_substr($last,0,500),$latency);if($attempt<=$retries)usleep(min(500000,100000*$attempt));
        }while($attempt<=$retries);
        return['ok'=>false,'status'=>$http??0,'error'=>['code'=>'upstream_error','message'=>$last],'attempts'=>$attempt];
    }

    private static function recordHealth(PDO $pdo,int $id,bool $ok,int $http,?string $code,?string $message,int $latency): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$driver==='sqlite'
            ? 'INSERT INTO service_connector_health(connector_id,last_ok_at,last_error_at,last_http_status,last_error_code,last_error_message,latency_ms) VALUES(:id,CASE WHEN :ok=1 THEN CURRENT_TIMESTAMP END,CASE WHEN :ok2=0 THEN CURRENT_TIMESTAMP END,:http,:code,:message,:latency) ON CONFLICT(connector_id) DO UPDATE SET last_ok_at=CASE WHEN :ok3=1 THEN CURRENT_TIMESTAMP ELSE last_ok_at END,last_error_at=CASE WHEN :ok4=0 THEN CURRENT_TIMESTAMP ELSE last_error_at END,last_http_status=:http2,last_error_code=:code2,last_error_message=:message2,latency_ms=:latency2,updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO service_connector_health(connector_id,last_ok_at,last_error_at,last_http_status,last_error_code,last_error_message,latency_ms) VALUES(:id,CASE WHEN :ok=1 THEN CURRENT_TIMESTAMP END,CASE WHEN :ok2=0 THEN CURRENT_TIMESTAMP END,:http,:code,:message,:latency) ON CONFLICT(connector_id) DO UPDATE SET last_ok_at=CASE WHEN :ok3=1 THEN CURRENT_TIMESTAMP ELSE service_connector_health.last_ok_at END,last_error_at=CASE WHEN :ok4=0 THEN CURRENT_TIMESTAMP ELSE service_connector_health.last_error_at END,last_http_status=EXCLUDED.last_http_status,last_error_code=EXCLUDED.last_error_code,last_error_message=EXCLUDED.last_error_message,latency_ms=EXCLUDED.latency_ms,updated_at=CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute(['id'=>$id,'ok'=>$ok?1:0,'ok2'=>$ok?1:0,'http'=>$http,'code'=>$code,'message'=>$message,'latency'=>$latency,'ok3'=>$ok?1:0,'ok4'=>$ok?1:0,'http2'=>$http,'code2'=>$code,'message2'=>$message,'latency2'=>$latency]);
    }
}
