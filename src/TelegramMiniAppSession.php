<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

/** Short-lived, replay-aware authentication for the customer Mini App. */
final class TelegramMiniAppSession
{
    public function __construct(private TelegramAssistantRepository $repository)
    {
    }

    /**
     * Validates official Telegram initData and exchanges it for an opaque
     * bearer token. A valid WebView refresh rotates the prior token instead of
     * dead-ending the customer; no token or initData is stored in plaintext.
     */
    public function start(int $profileId,string $initData,string $botToken,int $maximumAge=300,int $ttlSeconds=900): array
    {
        $profile=$this->repository->profileById($profileId)??throw new RuntimeException('پروفایل Mini App پیدا نشد.');
        if((int)$profile['is_enabled']!==1)throw new RuntimeException('دستیار Mini App فعال نیست.');
        $validated=TelegramWebAppAuth::validate($initData,$botToken,max(30,min(900,$maximumAge)));
        $user=$validated['user'];$userId=TelegramAssistantRepository::telegramId((string)$user['id'],false);
        $queryId=(string)($validated['query_id']??'');$initHash=hash('sha256',$initData);
        $replayKey=hash('sha256','miniapp-start|'.$profileId.'|'.$queryId.'|'.$initHash);
        $ttlSeconds=max(60,min(3600,$ttlSeconds));$expires=TelegramAssistantRepository::utc('+'.$ttlSeconds.' seconds');
        $token=self::token();$tokenHash=hash('sha256',$token);
        $sessionData=[
            'user'=>[
                'id'=>$userId,'first_name'=>(string)($user['first_name']??''),'last_name'=>(string)($user['last_name']??''),
                'username'=>(string)($user['username']??''),'language_code'=>(string)($user['language_code']??''),
            ],
            'auth_date'=>(int)$validated['auth_date'],'query_id'=>$queryId,
            'start_param'=>(string)($validated['raw']['start_param']??''),
        ];
        return $this->repository->transaction(function()use($profileId,$replayKey,$expires,$token,$tokenHash,$userId,$initHash,$sessionData):array{
            $claim=$this->repository->pdo()->prepare('INSERT INTO telegram_assistant_replays(replay_key,profile_id,purpose,response_encrypted,expires_at) VALUES(:key,:profile,\'miniapp_start\',NULL,:expires) ON CONFLICT(replay_key) DO NOTHING');
            $claim->execute(['key'=>$replayKey,'profile'=>$profileId,'expires'=>$expires]);
            $contactId=null;
            if($claim->rowCount()!==1){
                $replay=$this->repository->pdo()->prepare("SELECT 1 FROM telegram_assistant_replays WHERE replay_key=:key AND profile_id=:profile AND purpose='miniapp_start' AND expires_at>CURRENT_TIMESTAMP");
                $replay->execute(['key'=>$replayKey,'profile'=>$profileId]);
                if(!$replay->fetchColumn())throw new RuntimeException('این دادهٔ Mini App منقضی یا نامعتبر است.');
                $previous=$this->repository->pdo()->prepare('SELECT * FROM telegram_miniapp_sessions WHERE profile_id=:profile AND init_data_hash=:init AND telegram_user_id=:user AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1');
                $previous->execute(['profile'=>$profileId,'init'=>$initHash,'user'=>$userId]);$prior=$previous->fetch();
                if(!is_array($prior))throw new RuntimeException('نشست قبلی Mini App برای نوسازی پیدا نشد.');
                $priorData=json_decode(SecretStore::open((string)$prior['data_encrypted'],self::sessionContext((string)$prior['token_hash'])),true);
                if(!is_array($priorData)||!hash_equals($userId,(string)($priorData['user']['id']??'')))throw new RuntimeException('محتوای نشست قبلی Mini App معتبر نیست.');
                $contactId=$prior['contact_id']!==null?(int)$prior['contact_id']:null;
                $this->repository->pdo()->prepare('UPDATE telegram_miniapp_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE profile_id=:profile AND init_data_hash=:init AND revoked_at IS NULL')
                    ->execute(['profile'=>$profileId,'init'=>$initHash]);
            }
            $sealed=SecretStore::seal(TelegramAssistantRepository::json($sessionData),self::sessionContext($tokenHash));
            $this->repository->pdo()->prepare('INSERT INTO telegram_miniapp_sessions(profile_id,contact_id,token_hash,telegram_user_id,init_data_hash,data_encrypted,expires_at) VALUES(:profile,:contact,:token,:user,:init,:data,:expires)')
                ->execute(['profile'=>$profileId,'contact'=>$contactId,'token'=>$tokenHash,'user'=>$userId,'init'=>$initHash,'data'=>$sealed,'expires'=>$expires]);
            $sessionId=(int)$this->repository->pdo()->lastInsertId();
            $response=['session_id'=>$sessionId,'telegram_user_id'=>$userId,'expires_at'=>$expires];
            $this->repository->pdo()->prepare('UPDATE telegram_assistant_replays SET response_encrypted=:response WHERE replay_key=:key')
                ->execute(['response'=>SecretStore::seal(TelegramAssistantRepository::json($response),self::replayContext($replayKey)),'key'=>$replayKey]);
            return $response+['token'=>$token,'data'=>$sessionData];
        });
    }

    /** Verifies an opaque bearer token and returns its decrypted scoped identity. */
    public function authenticate(string $token,?int $profileId=null): array
    {
        self::validateToken($token);$hash=hash('sha256',$token);
        $sql='SELECT * FROM telegram_miniapp_sessions WHERE token_hash=:hash AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP';
        $params=['hash'=>$hash];if($profileId!==null){$sql.=' AND profile_id=:profile';$params['profile']=$profileId;}
        $query=$this->repository->pdo()->prepare($sql);$query->execute($params);$row=$query->fetch();
        if(!is_array($row))throw new RuntimeException('نشست Mini App معتبر یا فعال نیست.');
        $decoded=json_decode(SecretStore::open((string)$row['data_encrypted'],self::sessionContext($hash)),true);
        if(!is_array($decoded)||!hash_equals((string)$row['telegram_user_id'],(string)($decoded['user']['id']??'')))throw new RuntimeException('محتوای نشست Mini App معتبر نیست.');
        $this->repository->pdo()->prepare('UPDATE telegram_miniapp_sessions SET last_used_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$row['id']]);
        return ['session_id'=>(int)$row['id'],'profile_id'=>(int)$row['profile_id'],'contact_id'=>$row['contact_id']!==null?(int)$row['contact_id']:null,'telegram_user_id'=>(string)$row['telegram_user_id'],'expires_at'=>(string)$row['expires_at'],'data'=>$decoded];
    }

    /** Binds the authenticated Telegram identity to exactly one profile-scoped contact. */
    public function bindContact(string $token,int $contactId): array
    {
        if($contactId<1)throw new RuntimeException('مخاطب Mini App معتبر نیست.');
        $session=$this->authenticate($token);$query=$this->repository->pdo()->prepare('SELECT id,profile_id,telegram_user_id FROM telegram_assistant_contacts WHERE id=:contact AND is_blocked=0');
        $query->execute(['contact'=>$contactId]);$contact=$query->fetch();
        if(!is_array($contact)||(int)$contact['profile_id']!==(int)$session['profile_id']
            ||!hash_equals((string)$session['telegram_user_id'],(string)$contact['telegram_user_id']))throw new RuntimeException('مخاطب Mini App با نشست تطابق ندارد.');
        $statement=$this->repository->pdo()->prepare('UPDATE telegram_miniapp_sessions SET contact_id=:contact,last_used_at=CURRENT_TIMESTAMP WHERE id=:id AND (contact_id IS NULL OR contact_id=:same_contact)');
        $statement->execute(['contact'=>$contactId,'id'=>$session['session_id'],'same_contact'=>$contactId]);
        if($statement->rowCount()!==1){$current=$this->authenticate($token);if((int)($current['contact_id']??0)!==$contactId)throw new RuntimeException('نشست Mini App قبلاً به مخاطب دیگری متصل شده است.');}
        return $this->authenticate($token);
    }

    public function revoke(string $token): bool
    {
        self::validateToken($token);$statement=$this->repository->pdo()->prepare('UPDATE telegram_miniapp_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_hash=:hash AND revoked_at IS NULL');
        $statement->execute(['hash'=>hash('sha256',$token)]);return $statement->rowCount()===1;
    }

    /**
     * Claims a short-lived replay key for mutation endpoints such as confirm or
     * cancel. Callers can later attach an encrypted deterministic response.
     */
    public function claimReplay(int $profileId,string $purpose,string $externalKey,int $ttlSeconds=900): string
    {
        if(preg_match('/^[a-z][a-z0-9_]{2,39}$/',$purpose)!==1)throw new RuntimeException('نوع replay معتبر نیست.');
        if(strlen($externalKey)<8||strlen($externalKey)>512)throw new RuntimeException('کلید idempotency معتبر نیست.');
        $key=hash('sha256',$profileId.'|'.$purpose.'|'.$externalKey);
        $statement=$this->repository->pdo()->prepare('INSERT INTO telegram_assistant_replays(replay_key,profile_id,purpose,response_encrypted,expires_at) VALUES(:key,:profile,:purpose,NULL,:expires) ON CONFLICT(replay_key) DO NOTHING');
        $statement->execute(['key'=>$key,'profile'=>$profileId,'purpose'=>$purpose,'expires'=>TelegramAssistantRepository::utc('+'.max(60,min(86400,$ttlSeconds)).' seconds')]);
        if($statement->rowCount()!==1)throw new RuntimeException('این درخواست قبلاً پردازش شده است.');
        return $key;
    }

    public function storeReplayResponse(string $replayKey,array $response): void
    {
        if(preg_match('/^[a-f0-9]{64}$/',$replayKey)!==1)throw new RuntimeException('کلید replay معتبر نیست.');
        $statement=$this->repository->pdo()->prepare('UPDATE telegram_assistant_replays SET response_encrypted=:response WHERE replay_key=:key AND expires_at>CURRENT_TIMESTAMP');
        $statement->execute(['response'=>SecretStore::seal(TelegramAssistantRepository::json($response),self::replayContext($replayKey)),'key'=>$replayKey]);
        if($statement->rowCount()!==1)throw new RuntimeException('ثبت پاسخ replay ناموفق بود.');
    }

    public function replayResponse(int $profileId,string $purpose,string $externalKey): ?array
    {
        $key=hash('sha256',$profileId.'|'.$purpose.'|'.$externalKey);
        $query=$this->repository->pdo()->prepare('SELECT response_encrypted FROM telegram_assistant_replays WHERE replay_key=:key AND expires_at>CURRENT_TIMESTAMP');$query->execute(['key'=>$key]);$sealed=$query->fetchColumn();
        if(!is_string($sealed)||$sealed==='')return null;
        $decoded=json_decode(SecretStore::open($sealed,self::replayContext($key)),true);
        return is_array($decoded)?$decoded:null;
    }

    private static function token(): string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private static function validateToken(string $token): void{if(preg_match('/^[A-Za-z0-9_-]{43}$/',$token)!==1)throw new RuntimeException('توکن نشست معتبر نیست.');}
    private static function sessionContext(string $hash): string{return 'telegram.miniapp-session.'.$hash;}
    private static function replayContext(string $hash): string{return 'telegram.assistant-replay.'.$hash;}
}
