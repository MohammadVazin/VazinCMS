<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

/** Telegram Bot API 10.x BusinessConnection normalization and rights policy. */
final class TelegramBusinessService
{
    private const OWNER_PIN_SETTING = 'business_owner_id_encrypted';

    private const RIGHT_KEYS = [
        'can_reply','can_read_messages','can_delete_sent_messages','can_delete_all_messages',
        'can_edit_name','can_edit_bio','can_edit_profile_photo','can_edit_username',
        'can_change_gift_settings','can_view_gifts_and_stars','can_convert_gifts_to_stars',
        'can_transfer_and_upgrade_gifts','can_transfer_stars','can_manage_stories',
    ];

    public function __construct(private TelegramAssistantRepository $repository)
    {
    }

    /**
     * Persists a Bot API BusinessConnection update.
     *
     * $authorizer must be explicitly true or a callback returning true before
     * the connection can become active. The default is quarantined/pending.
     */
    public function handleConnectionUpdate(int $connectionId,array $update,bool|callable|null $authorizer=null): array
    {
        $profile=$this->repository->requireProfileByConnection($connectionId);
        $opaque=self::connectionId((string)($update['id']??''));
        $user=is_array($update['user']??null)?$update['user']:[];
        $businessUser=TelegramAssistantRepository::telegramId((string)($user['id']??''),false);
        $userChat=TelegramAssistantRepository::telegramId((string)($update['user_chat_id']??''),false);
        $date=filter_var($update['date']??null,FILTER_VALIDATE_INT);
        if(!is_int($date)||$date<1)throw new RuntimeException('تاریخ BusinessConnection معتبر نیست.');
        $rights=self::normalizeRights($update['rights']??[]);
        $decision=$authorizer===true?true:($authorizer===false?false:(is_callable($authorizer)?$authorizer($businessUser,$update,$profile):null));
        if($decision!==null&&!is_bool($decision))$decision=null;
        $telegramEnabled=($update['is_enabled']??false)===true;
        $values=[
            'profile'=>(int)$profile['id'],'connection'=>$connectionId,'opaque'=>$opaque,
            'user'=>$businessUser,'chat'=>$userChat,'rights'=>TelegramAssistantRepository::json($rights),
            'decision'=>$decision,'telegram_enabled'=>$telegramEnabled,'connected'=>gmdate('Y-m-d H:i:s',$date),
            'disabled'=>$telegramEnabled?null:TelegramAssistantRepository::utc(),
        ];
        return $this->repository->transaction(function()use($values):array{
            $profile=$this->profileForUpdate($values['profile']);
            $lock=$this->repository->driver()==='pgsql'?' FOR UPDATE':'';
            $find=$this->repository->pdo()->prepare('SELECT * FROM telegram_business_connections WHERE connection_id=:connection AND business_connection_id=:opaque'.$lock);
            $find->execute(['connection'=>$values['connection'],'opaque'=>$values['opaque']]);$row=$find->fetch();
            $sameOwner=is_array($row)&&hash_equals((string)$row['business_user_id'],(string)$values['user']);
            $expected=$this->expectedOwnerId($profile);
            $ownerMismatch=(is_array($row)&&!$sameOwner)||($expected!==null&&!hash_equals($expected,(string)$values['user']));
            if($ownerMismatch){
                $authorization='rejected';
            }elseif($values['decision']===false){
                $authorization='rejected';
            }elseif(!$values['telegram_enabled']){
                // A disabled Telegram connection must be reviewed again after re-enabling.
                $authorization='pending';
            }elseif($values['decision']===true){
                $normalized=self::rights(['rights_json'=>$values['rights']]);
                if(($normalized['can_reply']??false)!==true){
                    $authorization='pending';
                }else{
                    if($expected===null){$profile=$this->pinExpectedOwner($profile,(string)$values['user']);$expected=(string)$values['user'];}
                    $authorization='authorized';
                }
            }elseif($sameOwner&&$expected!==null&&hash_equals($expected,(string)$values['user'])&&(string)$row['authorization_status']==='authorized'){
                $authorization='authorized';
            }elseif($sameOwner&&(string)$row['authorization_status']==='rejected'){
                $authorization='rejected';
            }else{
                $authorization='pending';
            }
            $baseEnabled=$this->baseConnectionEnabled($values['connection']);
            $enabled=$authorization==='authorized'&&$values['telegram_enabled']&&$baseEnabled&&(int)$profile['is_enabled']===1
                &&(self::rights(['rights_json'=>$values['rights']])['can_reply']??false)===true?1:0;
            if(is_array($row)){
                $this->repository->pdo()->prepare('UPDATE telegram_business_connections SET profile_id=:profile,business_user_id=:user,user_chat_id=:chat,rights_json=:rights,authorization_status=:authorization,is_enabled=:enabled,connected_at=:connected,disabled_at=:disabled,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                    ->execute(['profile'=>$values['profile'],'user'=>$values['user'],'chat'=>$values['chat'],'rights'=>$values['rights'],'authorization'=>$authorization,'enabled'=>$enabled,'connected'=>$values['connected'],'disabled'=>$values['disabled'],'id'=>$row['id']]);
            }else{
                $this->repository->pdo()->prepare('INSERT INTO telegram_business_connections(profile_id,connection_id,business_connection_id,business_user_id,user_chat_id,rights_json,authorization_status,is_enabled,connected_at,disabled_at) VALUES(:profile,:connection,:opaque,:user,:chat,:rights,:authorization,:enabled,:connected,:disabled)')
                    ->execute(['profile'=>$values['profile'],'connection'=>$values['connection'],'opaque'=>$values['opaque'],'user'=>$values['user'],'chat'=>$values['chat'],'rights'=>$values['rights'],'authorization'=>$authorization,'enabled'=>$enabled,'connected'=>$values['connected'],'disabled'=>$values['disabled']]);
            }
            $find->execute(['connection'=>$values['connection'],'opaque'=>$values['opaque']]);
            return (array)$find->fetch();
        });
    }

    /** Allows an owner-reviewed pending connection; still honors Telegram is_enabled and profile state. */
    public function authorize(int $connectionId,string $businessConnectionId,bool $allow=true): array
    {
        $row=$this->requireConnection($connectionId,$businessConnectionId,false);
        $this->reviewConnection((int)$row['profile_id'],(int)$row['id'],$allow);
        return $this->requireConnection($connectionId,$businessConnectionId,false);
    }

    /**
     * Returns the admin review queue without exposing Telegram's opaque
     * business_connection_id or full Telegram user/chat identifiers.
     */
    public function adminConnections(int $profileId): array
    {
        if($profileId<1)throw new RuntimeException('پروفایل دستیار معتبر نیست.');
        $profile=$this->repository->profileById($profileId)??throw new RuntimeException('پروفایل دستیار پیدا نشد.');
        $query=$this->repository->pdo()->prepare(
            'SELECT b.*,tc.is_enabled connection_enabled FROM telegram_business_connections b '
            .'JOIN telegram_connections tc ON tc.id=b.connection_id WHERE b.profile_id=:profile ORDER BY b.id DESC'
        );
        $query->execute(['profile'=>$profileId]);
        return array_map(fn(array $row):array=>$this->safeAdminConnection($row,$profile),$query->fetchAll());
    }

    /**
     * Reviews one pending row under a profile lock. The first approved owner is
     * encrypted into profile settings; every other owner then fails closed.
     */
    public function reviewConnection(int $profileId,int $businessRowId,bool $allow): array
    {
        if($profileId<1||$businessRowId<1)throw new RuntimeException('اتصال Business معتبر نیست.');
        return $this->repository->transaction(function()use($profileId,$businessRowId,$allow):array{
            $profile=$this->profileForUpdate($profileId);
            $row=$this->businessRowForUpdate($profileId,$businessRowId);
            $status=(string)$row['authorization_status'];
            if($status!=='pending'){
                if($allow&&$status==='authorized'){
                    $expected=$this->expectedOwnerId($profile);
                    if($expected===null||!hash_equals($expected,(string)$row['business_user_id'])){
                        $this->setAuthorization($businessRowId,'rejected',false);
                        return $this->safeAdminConnection($this->businessRowForUpdate($profileId,$businessRowId),$profile);
                    }
                    return $this->safeAdminConnection($row,$profile);
                }
                if(!$allow&&$status==='rejected')return $this->safeAdminConnection($row,$profile);
                throw new RuntimeException('این اتصال قبلاً بررسی شده است.');
            }
            if(!$allow){
                $this->setAuthorization($businessRowId,'rejected',false);
                return $this->safeAdminConnection($this->businessRowForUpdate($profileId,$businessRowId),$profile);
            }
            if($row['disabled_at']!==null)throw new RuntimeException('اتصال در Telegram غیرفعال است.');
            $rights=self::rights($row);
            if(($rights['can_reply']??false)!==true)throw new RuntimeException('مجوز پاسخ‌گویی Telegram برای این اتصال فعال نیست.');
            $expected=$this->expectedOwnerId($profile);
            $owner=(string)$row['business_user_id'];
            if($expected===null){
                $profile=$this->pinExpectedOwner($profile,$owner);
            }elseif(!hash_equals($expected,$owner)){
                $this->setAuthorization($businessRowId,'rejected',false);
                return $this->safeAdminConnection($this->businessRowForUpdate($profileId,$businessRowId),$profile);
            }
            $enabled=(int)$profile['is_enabled']===1&&(int)$row['connection_enabled']===1;
            $this->setAuthorization($businessRowId,'authorized',$enabled);
            return $this->safeAdminConnection($this->businessRowForUpdate($profileId,$businessRowId),$profile);
        });
    }

    /**
     * Enables or disables the local assistant runtime. Approval and auto-reply
     * remain independent gates. Disabling also cancels undelivered work.
     */
    public function setProfileEnabled(int $profileId,bool $enabled): array
    {
        if($profileId<1)throw new RuntimeException('پروفایل دستیار معتبر نیست.');
        return $this->repository->transaction(function()use($profileId,$enabled):array{
            $profile=$this->profileForUpdate($profileId);
            $this->repository->pdo()->prepare('UPDATE telegram_assistant_profiles SET is_enabled=:enabled,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute(['enabled'=>$enabled?1:0,'id'=>$profileId]);
            $profile['is_enabled']=$enabled?1:0;
            $this->repository->pdo()->prepare('UPDATE telegram_business_connections SET is_enabled=0,updated_at=CURRENT_TIMESTAMP WHERE profile_id=:profile')
                ->execute(['profile'=>$profileId]);
            if(!$enabled){
                $this->repository->pdo()->prepare("UPDATE telegram_assistant_outbox SET status='cancelled',locked_at=NULL,lock_token=NULL,last_error='profile_disabled',updated_at=CURRENT_TIMESTAMP WHERE profile_id=:profile AND status IN ('pending','processing')")
                    ->execute(['profile'=>$profileId]);
                return $this->profileForUpdate($profileId);
            }
            $expected=$this->expectedOwnerId($profile);
            if($expected===null)return $this->profileForUpdate($profileId);
            $query=$this->repository->pdo()->prepare(
                "SELECT b.*,tc.is_enabled connection_enabled FROM telegram_business_connections b JOIN telegram_connections tc ON tc.id=b.connection_id WHERE b.profile_id=:profile AND b.authorization_status='authorized'"
            );
            $query->execute(['profile'=>$profileId]);
            foreach($query->fetchAll()as$row){
                $ownerMatches=hash_equals($expected,(string)$row['business_user_id']);
                if(!$ownerMatches){$this->setAuthorization((int)$row['id'],'rejected',false);continue;}
                $canReply=(self::rights($row)['can_reply']??false)===true;
                $runtime=$row['disabled_at']===null&&(int)$row['connection_enabled']===1&&$canReply;
                $this->setAuthorization((int)$row['id'],'authorized',$runtime);
            }
            return $this->profileForUpdate($profileId);
        });
    }

    public function connection(int $connectionId,string $businessConnectionId,bool $activeOnly=true): ?array
    {
        $sql='SELECT b.*,p.is_enabled profile_enabled,p.auto_reply_enabled,p.booking_enabled,p.handoff_enabled,p.locale,p.timezone,p.assistant_name,tc.webhook_key '
            .'FROM telegram_business_connections b JOIN telegram_assistant_profiles p ON p.id=b.profile_id JOIN telegram_connections tc ON tc.id=b.connection_id '
            .'WHERE b.connection_id=:connection AND b.business_connection_id=:opaque';
        if($activeOnly)$sql.=" AND b.is_enabled=1 AND b.authorization_status='authorized' AND p.is_enabled=1 AND tc.is_enabled=1";
        $query=$this->repository->pdo()->prepare($sql);$query->execute(['connection'=>$connectionId,'opaque'=>self::connectionId($businessConnectionId)]);$row=$query->fetch();
        return is_array($row)?$row:null;
    }

    public function requireConnection(int $connectionId,string $businessConnectionId,bool $activeOnly=true): array
    {
        return $this->connection($connectionId,$businessConnectionId,$activeOnly)??throw new RuntimeException('BusinessConnection مجاز و فعال پیدا نشد.');
    }

    /**
     * Normalizes business_message/edited_business_message without retaining its
     * raw payload. The result is suitable for TelegramAssistantRepository.
     */
    public function normalizeMessage(int $connectionId,array $message,?int $updateId=null,bool $requireActive=true): array
    {
        $opaque=self::connectionId((string)($message['business_connection_id']??''));
        $business=$this->requireConnection($connectionId,$opaque,$requireActive);
        $chat=is_array($message['chat']??null)?$message['chat']:[];
        $chatId=TelegramAssistantRepository::telegramId((string)($chat['id']??''),true);
        $chatType=(string)($chat['type']??'private');
        if($chatType!=='private')throw new RuntimeException('دستیار فقط گفت‌وگوی خصوصی مشتری را پردازش می‌کند.');
        $messageId=filter_var($message['message_id']??null,FILTER_VALIDATE_INT);
        $date=filter_var($message['date']??null,FILTER_VALIDATE_INT);
        if(!is_int($messageId)||$messageId<1||!is_int($date)||$date<1)throw new RuntimeException('شناسه یا تاریخ business_message معتبر نیست.');
        $from=is_array($message['from']??null)?$message['from']:[];
        $senderBot=is_array($message['sender_business_bot']??null)?$message['sender_business_bot']:null;
        $fromId=(string)($from['id']??'');
        if($senderBot!==null)$actor='bot';
        elseif($fromId!==''&&hash_equals((string)$business['business_user_id'],$fromId))$actor='owner';
        elseif(($from['is_bot']??false)===true)$actor='external_bot';
        else $actor='customer';
        $direction=$actor==='customer'||$actor==='external_bot'?'incoming':'outgoing';
        $text=(string)($message['text']??$message['caption']??'');
        $contentType=self::contentType($message);
        $edit=filter_var($message['edit_date']??null,FILTER_VALIDATE_INT);
        $senderBotId=is_array($senderBot)&&isset($senderBot['id'])?(string)$senderBot['id']:null;
        return [
            'connection_id'=>$connectionId,'business_connection_row_id'=>(int)$business['id'],
            'business'=>$business,'telegram_update_id'=>$updateId,'telegram_chat_id'=>$chatId,
            'telegram_message_id'=>$messageId,'actor'=>$actor,'direction'=>$direction,
            'content_type'=>$contentType,'text'=>$text,'message_date'=>gmdate('Y-m-d H:i:s',$date),
            'edited_at'=>is_int($edit)&&$edit>0?gmdate('Y-m-d H:i:s',$edit):null,
            'sender'=>$from,'metadata'=>[
                'media_type'=>$contentType,'is_from_offline'=>($message['is_from_offline']??false)===true,
                'reply_to_message_id'=>isset($message['reply_to_message']['message_id'])?(int)$message['reply_to_message']['message_id']:null,
                'sender_business_bot_id'=>$senderBotId,
            ],
        ];
    }

    /** Returns whether a reply may legally be attempted at this instant. */
    public function canReply(array $business,string $lastCustomerAt,?int $now=null): bool
    {
        if((int)($business['is_enabled']??0)!==1||(string)($business['authorization_status']??'')!=='authorized')return false;
        $rights=self::rights($business);if(($rights['can_reply']??false)!==true)return false;
        $last=TelegramAssistantRepository::timestamp($lastCustomerAt);$now??=time();
        return $last>0&&$last<=($now+300)&&($last+86400)>=$now;
    }

    public function canRead(array $business): bool
    {
        return (int)($business['is_enabled']??0)===1&&(self::rights($business)['can_read_messages']??false)===true;
    }

    public static function rights(array $business): array
    {
        $decoded=json_decode((string)($business['rights_json']??'{}'),true);
        return is_array($decoded)?self::normalizeRights($decoded):[];
    }

    public static function normalizeRights(mixed $rights): array
    {
        $rights=is_array($rights)?$rights:[];$normalized=[];
        foreach(self::RIGHT_KEYS as $key)$normalized[$key]=($rights[$key]??false)===true;
        return $normalized;
    }

    public static function connectionId(string $value): string
    {
        if(strlen($value)<1||strlen($value)>255||preg_match('/^[A-Za-z0-9_-]+$/',$value)!==1)throw new RuntimeException('شناسهٔ BusinessConnection معتبر نیست.');
        return $value;
    }

    private function profileForUpdate(int $profileId): array
    {
        $lock=$this->repository->driver()==='pgsql'?' FOR UPDATE':'';
        $query=$this->repository->pdo()->prepare('SELECT * FROM telegram_assistant_profiles WHERE id=:id'.$lock);
        $query->execute(['id'=>$profileId]);$row=$query->fetch();
        return is_array($row)?$row:throw new RuntimeException('پروفایل دستیار پیدا نشد.');
    }

    private function businessRowForUpdate(int $profileId,int $businessRowId): array
    {
        $lock=$this->repository->driver()==='pgsql'?' FOR UPDATE OF b':'';
        $query=$this->repository->pdo()->prepare(
            'SELECT b.*,tc.is_enabled connection_enabled FROM telegram_business_connections b '
            .'JOIN telegram_connections tc ON tc.id=b.connection_id WHERE b.profile_id=:profile AND b.id=:id'.$lock
        );
        $query->execute(['profile'=>$profileId,'id'=>$businessRowId]);$row=$query->fetch();
        return is_array($row)?$row:throw new RuntimeException('اتصال Business پیدا نشد.');
    }

    private function expectedOwnerId(array $profile): ?string
    {
        $settings=self::profileSettings($profile);$sealed=(string)($settings[self::OWNER_PIN_SETTING]??'');
        if($sealed==='')return null;
        return TelegramAssistantRepository::telegramId(SecretStore::open($sealed,self::ownerPinContext((int)$profile['id'])),false);
    }

    private function pinExpectedOwner(array $profile,string $ownerId): array
    {
        $ownerId=TelegramAssistantRepository::telegramId($ownerId,false);$settings=self::profileSettings($profile);
        $settings[self::OWNER_PIN_SETTING]=SecretStore::seal($ownerId,self::ownerPinContext((int)$profile['id']));
        $this->repository->pdo()->prepare('UPDATE telegram_assistant_profiles SET settings_json=:settings,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute(['settings'=>TelegramAssistantRepository::json($settings),'id'=>$profile['id']]);
        $profile['settings_json']=TelegramAssistantRepository::json($settings);
        return $profile;
    }

    private function safeAdminConnection(array $row,array $profile): array
    {
        $expected=$this->expectedOwnerId($profile);$owner=(string)$row['business_user_id'];$rights=self::rights($row);
        $ownerMatches=$expected===null?null:hash_equals($expected,$owner);
        return [
            'id'=>(int)$row['id'],'reference'=>strtoupper(substr(hash('sha256',(string)$row['business_connection_id']),0,10)),
            'owner_id_masked'=>self::maskTelegramId($owner),'authorization_status'=>(string)$row['authorization_status'],
            'telegram_enabled'=>$row['disabled_at']===null,'profile_enabled'=>(int)$profile['is_enabled']===1,
            'connection_enabled'=>(int)($row['connection_enabled']??0)===1,'runtime_enabled'=>(int)$row['is_enabled']===1,
            'expected_owner_pinned'=>$expected!==null,'owner_matches_pin'=>$ownerMatches,
            'rights'=>['can_reply'=>($rights['can_reply']??false)===true,'can_read_messages'=>($rights['can_read_messages']??false)===true],
            'rights_ready'=>($rights['can_reply']??false)===true,
            'connected_at'=>(string)$row['connected_at'],'last_seen_at'=>(string)$row['last_seen_at'],
        ];
    }

    private function setAuthorization(int $rowId,string $status,bool $enabled): void
    {
        $this->repository->pdo()->prepare('UPDATE telegram_business_connections SET authorization_status=:status,is_enabled=:enabled,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute(['status'=>$status,'enabled'=>$enabled?1:0,'id'=>$rowId]);
    }

    private function baseConnectionEnabled(int $connectionId): bool
    {
        $query=$this->repository->pdo()->prepare('SELECT is_enabled FROM telegram_connections WHERE id=:id');$query->execute(['id'=>$connectionId]);
        return (int)$query->fetchColumn()===1;
    }

    private static function profileSettings(array $profile): array
    {
        $settings=json_decode((string)($profile['settings_json']??'{}'),true);
        if(!is_array($settings)||array_is_list($settings))throw new RuntimeException('تنظیمات پروفایل دستیار معتبر نیست.');
        return $settings;
    }

    private static function ownerPinContext(int $profileId): string{return 'telegram.business-owner.'.$profileId;}

    private static function maskTelegramId(string $value): string
    {
        $length=strlen($value);if($length<=4)return str_repeat('•',max(3,$length));
        return substr($value,0,2).str_repeat('•',max(3,$length-4)).substr($value,-2);
    }

    private static function contentType(array $message): string
    {
        foreach(['text','photo','video','animation','audio','voice','video_note','document','sticker','contact','location','venue','poll']as$type){
            if(array_key_exists($type,$message))return $type;
        }
        return 'unknown';
    }
}
