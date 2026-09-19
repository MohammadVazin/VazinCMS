<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

final class PublicationService
{
    public static function processDue(): string
    {
        if (!DeliveryPolicy::externalEnabled()) return 'ارسال خارجی غیرفعال است؛ 0 کار انتشار پردازش شد.';
        $pdo = Database::connection();
        $statement = $pdo->query(
            "SELECT j.*,p.title,p.body,p.slug,p.locale FROM publication_jobs j JOIN cms_pages p ON p.id=j.page_id "
            . "WHERE j.status IN ('scheduled','partial') AND j.scheduled_at<=CURRENT_TIMESTAMP "
            . "AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=CURRENT_TIMESTAMP) ORDER BY j.id LIMIT 10"
        );
        $count = 0;
        foreach ($statement->fetchAll() as $job) { self::process($pdo, $job); $count++; }
        return $count . ' کار انتشار پردازش شد.';
    }

    private static function process(PDO $pdo, array $job): void
    {
        DeliveryPolicy::assertExternalEnabled();
        $lockToken = bin2hex(random_bytes(16));
        $lock = $pdo->prepare("UPDATE publication_jobs SET status='processing',locked_at=CURRENT_TIMESTAMP,lock_token=:token,attempts=attempts+1 WHERE id=:id AND status IN ('scheduled','partial')");
        $lock->execute(['token'=>$lockToken,'id'=>$job['id']]);
        if ($lock->rowCount() !== 1) return;
        $ids = array_map('intval', json_decode((string)$job['destination_ids'], true) ?: []);
        $ok = 0; $failed = 0; $last = '';
        foreach ($ids as $id) {
            $statement = $pdo->prepare('SELECT * FROM social_destinations WHERE id=:id AND is_enabled=TRUE');
            $statement->execute(['id'=>$id]);
            $destination = $statement->fetch();
            if (!$destination) continue;
            try {
                $result = self::deliver($destination, $job); $status = 'published'; $ok++;
            } catch (Throwable $error) {
                $result = ['http'=>null,'response'=>'','ref'=>null]; $status = 'failed'; $last = $error->getMessage(); $failed++;
            }
            $sql = 'INSERT INTO publication_deliveries(job_id,destination_id,status,attempts,http_status,response_excerpt,error_message,published_ref,last_attempt_at) '
                . 'VALUES(:job,:destination,:status,1,:http,:response,:error,:ref,CURRENT_TIMESTAMP) '
                . 'ON CONFLICT(job_id,destination_id) DO UPDATE SET status=:status2,attempts=publication_deliveries.attempts+1,http_status=:http2,response_excerpt=:response2,error_message=:error2,published_ref=:ref2,last_attempt_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP';
            $pdo->prepare($sql)->execute([
                'job'=>$job['id'],'destination'=>$id,'status'=>$status,'http'=>$result['http'],'response'=>mb_substr($result['response'],0,1000),'error'=>$status==='failed'?$last:null,'ref'=>$result['ref'],
                'status2'=>$status,'http2'=>$result['http'],'response2'=>mb_substr($result['response'],0,1000),'error2'=>$status==='failed'?$last:null,'ref2'=>$result['ref'],
            ]);
        }
        $attempt = (int)$job['attempts'] + 1;
        $final = $failed === 0 ? 'published' : ($ok > 0 ? 'partial' : 'failed');
        $retry = $failed > 0 && $attempt < (int)$job['max_attempts'] ? date('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** min(6, $attempt)))) : null;
        if ($retry !== null) $final = $ok > 0 ? 'partial' : 'scheduled';
        $pdo->prepare('UPDATE publication_jobs SET status=:status,next_attempt_at=:next,last_error=:error,locked_at=NULL,lock_token=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND lock_token=:token')
            ->execute(['status'=>$final,'next'=>$retry,'error'=>$last?:null,'id'=>$job['id'],'token'=>$lockToken]);
    }

    private static function deliver(array $destination, array $job): array
    {
        $provider = (string)$destination['provider'];
        DeliveryPolicy::assertProviderEnabled($provider);
        $token = PublishingCrypto::decrypt((string)$destination['encrypted_token']);
        $base = rtrim((string)getenv('APP_URL'), '/');
        $url = $base . '/' . $job['locale'] . '/page/' . $job['slug'];
        $payload = ['title'=>$job['title'],'text'=>trim(strip_tags((string)$job['body']))."\n\n".$url,'url'=>$url,'published_at'=>gmdate('c')];
        $headers = ['Content-Type: application/json'];
        switch ($provider) {
            case 'telegram':
                $endpoint = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
                $body = ['chat_id'=>$destination['account_ref'],'text'=>$payload['text'],'disable_web_page_preview'=>false];
                break;
            case 'vk':
                $endpoint = 'https://api.vk.com/method/wall.post';
                $body = ['owner_id'=>$destination['account_ref'],'message'=>$payload['text'],'access_token'=>$token,'v'=>'5.199'];
                break;
            case 'x':
                $endpoint = 'https://api.x.com/2/tweets';
                $body = ['text'=>mb_substr($payload['text'],0,280)];
                $headers[] = 'Authorization: Bearer ' . $token;
                break;
            default:
                $endpoint = (string)$destination['endpoint']; $body = $payload;
                $headers[] = 'X-Vazin-Signature: ' . hash_hmac('sha256', json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $token);
        }
        $handle = curl_init($endpoint);
        if ($handle === false) throw new RuntimeException('راه‌اندازی ارسال خارجی ناموفق بود.');
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $response = (string)curl_exec($handle); $http = (int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        if ($error !== '' || $http < 200 || $http >= 300) throw new RuntimeException($error ?: "پاسخ HTTP $http");
        $json = json_decode($response,true); $ref = (string)($json['result']['message_id']??$json['response']['post_id']??$json['data']['id']??'');
        return ['http'=>$http,'response'=>$response,'ref'=>$ref?:null];
    }
}
