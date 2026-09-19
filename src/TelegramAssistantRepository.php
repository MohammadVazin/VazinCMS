<?php
declare(strict_types=1);

namespace VazinCMS;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Persistence boundary for Telegram Assistant conversations.
 *
 * Public API intentionally accepts normalized values only. Raw Telegram update
 * bodies must not be passed here because message text is always encrypted and
 * metadata is restricted to a small, non-content allow-list.
 */
final class TelegramAssistantRepository
{
    private const MESSAGE_METADATA_KEYS = [
        'media_type', 'file_id', 'file_unique_id', 'mime_type', 'file_size',
        'width', 'height', 'duration', 'has_media_spoiler', 'is_from_offline',
        'reply_to_message_id', 'sender_business_bot_id',
    ];

    private PDO $pdo;
    /** PDO does not report a SQLite transaction opened with BEGIN IMMEDIATE. */
    private int $transactionDepth = 0;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /** Exposes the shared PDO for the other assistant domain services. */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Runs one portable transaction; SQLite obtains the write lock up front. */
    public function transaction(callable $operation): mixed
    {
        if ($this->transactionDepth > 0 || $this->pdo->inTransaction()) return $operation($this->pdo);
        $sqlite = $this->driver() === 'sqlite';
        if ($sqlite) $this->pdo->exec('BEGIN IMMEDIATE'); else $this->pdo->beginTransaction();
        $this->transactionDepth = 1;
        try {
            $result = $operation($this->pdo);
            if ($sqlite) $this->pdo->exec('COMMIT'); else $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            try {
                if ($sqlite) $this->pdo->exec('ROLLBACK');
                elseif ($this->pdo->inTransaction()) $this->pdo->rollBack();
            } catch (Throwable) {}
            throw $error;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    /** Creates or updates the per-site assistant profile. Network features stay off by default. */
    public function saveProfile(int $connectionId, array $input): array
    {
        if ($connectionId < 1) throw new RuntimeException('اتصال Telegram معتبر نیست.');
        $name = trim((string)($input['assistant_name'] ?? ''));
        $locale = strtolower(trim((string)($input['locale'] ?? 'ru')));
        $timezone = trim((string)($input['timezone'] ?? 'UTC'));
        $avatar = trim((string)($input['avatar_url'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('نام دستیار معتبر نیست.');
        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $locale) !== 1) throw new RuntimeException('زبان دستیار معتبر نیست.');
        try { new DateTimeZone($timezone); } catch (Throwable) { throw new RuntimeException('منطقهٔ زمانی معتبر نیست.'); }
        if ($avatar !== '' && filter_var($avatar, FILTER_VALIDATE_URL) === false) throw new RuntimeException('نشانی تصویر دستیار معتبر نیست.');
        $settings = $input['settings'] ?? [];
        if (!is_array($settings)) throw new RuntimeException('تنظیمات دستیار معتبر نیست.');
        $flags = [
            'auto_reply_enabled'=>!empty($input['auto_reply_enabled']) ? 1 : 0,
            'booking_enabled'=>!empty($input['booking_enabled']) ? 1 : 0,
            'handoff_enabled'=>array_key_exists('handoff_enabled', $input) ? (!empty($input['handoff_enabled']) ? 1 : 0) : 1,
            'is_enabled'=>!empty($input['is_enabled']) ? 1 : 0,
        ];
        $welcome = trim((string)($input['welcome'] ?? ''));
        $welcomeEncrypted = $welcome === '' ? null : SecretStore::seal($welcome, self::profileContext($connectionId));

        return $this->transaction(function () use ($connectionId, $name, $locale, $timezone, $avatar, $settings, $flags, $welcomeEncrypted): array {
            $row = $this->profileByConnection($connectionId);
            if ($row === null) {
                $statement = $this->pdo->prepare(
                    'INSERT INTO telegram_assistant_profiles(connection_id,assistant_name,locale,timezone,avatar_url,welcome_encrypted,settings_json,auto_reply_enabled,booking_enabled,handoff_enabled,is_enabled) '
                    . 'VALUES(:connection,:name,:locale,:timezone,:avatar,:welcome,:settings,:auto,:booking,:handoff,:enabled)'
                );
                $statement->execute([
                    'connection'=>$connectionId, 'name'=>$name, 'locale'=>$locale, 'timezone'=>$timezone,
                    'avatar'=>$avatar, 'welcome'=>$welcomeEncrypted, 'settings'=>self::json($settings),
                    'auto'=>$flags['auto_reply_enabled'], 'booking'=>$flags['booking_enabled'],
                    'handoff'=>$flags['handoff_enabled'], 'enabled'=>$flags['is_enabled'],
                ]);
            } else {
                $statement = $this->pdo->prepare(
                    'UPDATE telegram_assistant_profiles SET assistant_name=:name,locale=:locale,timezone=:timezone,avatar_url=:avatar,welcome_encrypted=:welcome,settings_json=:settings,auto_reply_enabled=:auto,booking_enabled=:booking,handoff_enabled=:handoff,is_enabled=:enabled,updated_at=CURRENT_TIMESTAMP WHERE connection_id=:connection'
                );
                $statement->execute([
                    'connection'=>$connectionId, 'name'=>$name, 'locale'=>$locale, 'timezone'=>$timezone,
                    'avatar'=>$avatar, 'welcome'=>$welcomeEncrypted, 'settings'=>self::json($settings),
                    'auto'=>$flags['auto_reply_enabled'], 'booking'=>$flags['booking_enabled'],
                    'handoff'=>$flags['handoff_enabled'], 'enabled'=>$flags['is_enabled'],
                ]);
            }
            return $this->requireProfileByConnection($connectionId);
        });
    }

    public function profileByConnection(int $connectionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM telegram_assistant_profiles WHERE connection_id=:connection');
        $statement->execute(['connection'=>$connectionId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function requireProfileByConnection(int $connectionId): array
    {
        return $this->profileByConnection($connectionId) ?? throw new RuntimeException('پروفایل دستیار Telegram پیدا نشد.');
    }

    public function profileById(int $profileId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM telegram_assistant_profiles WHERE id=:id');
        $statement->execute(['id'=>$profileId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function profileWelcome(array $profile): string
    {
        $sealed = (string)($profile['welcome_encrypted'] ?? '');
        return $sealed === '' ? '' : SecretStore::open($sealed, self::profileContext((int)$profile['connection_id']));
    }

    /** Upserts the customer identity without storing raw message payloads. */
    public function upsertContact(array $business, array $sender, string $chatId): array
    {
        self::telegramId($chatId, true);
        $userId = isset($sender['id']) ? (string)$sender['id'] : '';
        if ($userId !== '') self::telegramId($userId, false);
        $values = [
            'profile'=>(int)$business['profile_id'], 'business'=>(int)$business['id'], 'chat'=>$chatId,
            'user'=>$userId, 'first'=>mb_substr(trim((string)($sender['first_name'] ?? '')), 0, 255),
            'last'=>mb_substr(trim((string)($sender['last_name'] ?? '')), 0, 255),
            'username'=>mb_substr(trim((string)($sender['username'] ?? '')), 0, 255),
            'language'=>mb_substr(trim((string)($sender['language_code'] ?? '')), 0, 32),
            'metadata'=>self::json(['is_premium'=>(bool)($sender['is_premium'] ?? false)]),
        ];
        return $this->transaction(function () use ($values): array {
            $find = $this->pdo->prepare('SELECT * FROM telegram_assistant_contacts WHERE business_connection_row_id=:business AND telegram_chat_id=:chat');
            $find->execute(['business'=>$values['business'], 'chat'=>$values['chat']]);
            $row = $find->fetch();
            if (is_array($row)) {
                $this->pdo->prepare('UPDATE telegram_assistant_contacts SET telegram_user_id=:user,first_name=:first,last_name=:last,username=:username,language_code=:language,metadata_json=:metadata,last_seen_at=CURRENT_TIMESTAMP WHERE id=:id')
                    ->execute(['user'=>$values['user'],'first'=>$values['first'],'last'=>$values['last'],'username'=>$values['username'],'language'=>$values['language'],'metadata'=>$values['metadata'],'id'=>$row['id']]);
                $find->execute(['business'=>$values['business'], 'chat'=>$values['chat']]);
                return (array)$find->fetch();
            }
            $this->pdo->prepare('INSERT INTO telegram_assistant_contacts(profile_id,business_connection_row_id,telegram_chat_id,telegram_user_id,first_name,last_name,username,language_code,metadata_json) VALUES(:profile,:business,:chat,:user,:first,:last,:username,:language,:metadata)')
                ->execute($values);
            $find->execute(['business'=>$values['business'], 'chat'=>$values['chat']]);
            return (array)$find->fetch();
        });
    }

    /** Finds the customer attached to one Business chat without mutating identity fields. */
    public function contactByBusinessChat(int $businessRowId, string $chatId): ?array
    {
        self::telegramId($chatId, true);
        $statement=$this->pdo->prepare('SELECT * FROM telegram_assistant_contacts WHERE business_connection_row_id=:business AND telegram_chat_id=:chat LIMIT 1');
        $statement->execute(['business'=>$businessRowId,'chat'=>$chatId]);
        $row=$statement->fetch();
        return is_array($row)?$row:null;
    }

    /** Returns one durable inbox conversation for a Business chat. */
    public function findOrCreateConversation(array $business, array $contact, string $chatId): array
    {
        return $this->transaction(function () use ($business, $contact, $chatId): array {
            $query = $this->pdo->prepare('SELECT * FROM telegram_assistant_conversations WHERE business_connection_row_id=:business AND telegram_chat_id=:chat');
            $query->execute(['business'=>$business['id'], 'chat'=>$chatId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                $this->pdo->prepare('INSERT INTO telegram_assistant_conversations(profile_id,business_connection_row_id,contact_id,telegram_chat_id) VALUES(:profile,:business,:contact,:chat)')
                    ->execute(['profile'=>$business['profile_id'], 'business'=>$business['id'], 'contact'=>$contact['id'], 'chat'=>$chatId]);
                $query->execute(['business'=>$business['id'], 'chat'=>$chatId]);
                $row = $query->fetch();
            }
            return is_array($row) ? $row : throw new RuntimeException('ساخت گفت‌وگوی دستیار ناموفق بود.');
        });
    }

    /**
     * Stores an encrypted message idempotently.
     * Returns ['created'=>bool,'message'=>row].
     */
    public function storeMessage(array $normalized, int $retentionDays = TelegramAssistantPrivacy::DEFAULT_RETENTION_DAYS): array
    {
        $required = ['connection_id','business_connection_row_id','conversation_id','telegram_chat_id','telegram_message_id','actor','direction','message_date'];
        foreach ($required as $key) if (!array_key_exists($key, $normalized)) throw new RuntimeException('پیام نرمال‌شده ناقص است: ' . $key);
        $chatId = (string)$normalized['telegram_chat_id'];
        self::telegramId($chatId, true);
        $messageId = filter_var($normalized['telegram_message_id'], FILTER_VALIDATE_INT);
        if (!is_int($messageId) || $messageId < 1) throw new RuntimeException('شناسهٔ پیام معتبر نیست.');
        $actor = (string)$normalized['actor'];
        $direction = (string)$normalized['direction'];
        if (!in_array($actor, ['customer','owner','bot','operator','external_bot','system'], true)
            || !in_array($direction, ['incoming','outgoing','system'], true)) throw new RuntimeException('نقش پیام معتبر نیست.');
        $text = (string)($normalized['text'] ?? '');
        $content = self::json(['text'=>$text]);
        $protected = TelegramAssistantPrivacy::protectContent((int)$normalized['connection_id'], $content, $retentionDays);
        $metadata = [];
        foreach ((array)($normalized['metadata'] ?? []) as $key=>$value) {
            if (in_array((string)$key, self::MESSAGE_METADATA_KEYS, true) && (is_scalar($value) || $value === null)) $metadata[(string)$key] = $value;
        }
        $parameters = [
            'connection'=>(int)$normalized['connection_id'], 'business'=>(int)$normalized['business_connection_row_id'],
            'conversation'=>(int)$normalized['conversation_id'], 'update'=>$normalized['telegram_update_id'] ?? null,
            'chat'=>$chatId, 'message'=>$messageId, 'actor'=>$actor, 'direction'=>$direction,
            'type'=>mb_substr((string)($normalized['content_type'] ?? 'text'), 0, 24),
            'content'=>$protected['content_encrypted'], 'content_key'=>$protected['content_key'], 'hash'=>hash('sha256', $content),
            'metadata'=>self::json($metadata), 'date'=>(string)$normalized['message_date'],
            'edited'=>$normalized['edited_at'] ?? null, 'expires'=>$protected['expires_at'],
        ];
        return $this->transaction(function () use ($parameters, $actor): array {
            $insert = $this->pdo->prepare(
                'INSERT INTO telegram_assistant_messages(connection_id,business_connection_row_id,conversation_id,telegram_update_id,telegram_chat_id,telegram_message_id,actor,direction,content_type,content_encrypted,content_key,content_hash,metadata_json,message_date,edited_at,expires_at) '
                . 'VALUES(:connection,:business,:conversation,:update,:chat,:message,:actor,:direction,:type,:content,:content_key,:hash,:metadata,:date,:edited,:expires) '
                . 'ON CONFLICT(connection_id,business_connection_row_id,telegram_chat_id,telegram_message_id) DO NOTHING'
            );
            $insert->execute($parameters);
            $created = $insert->rowCount() === 1;
            $conversationSql = $actor === 'customer'
                ? 'UPDATE telegram_assistant_conversations SET unread_count=unread_count+1,last_customer_at=:date,last_message_at=:date2,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
                : 'UPDATE telegram_assistant_conversations SET last_operator_at=CASE WHEN :is_operator=1 THEN :date ELSE last_operator_at END,last_message_at=:date2,updated_at=CURRENT_TIMESTAMP WHERE id=:id';
            if ($created) {
                $this->pdo->prepare($conversationSql)->execute($actor === 'customer'
                    ? ['date'=>$parameters['date'],'date2'=>$parameters['date'],'id'=>$parameters['conversation']]
                    : ['is_operator'=>in_array($actor,['owner','operator'],true)?1:0,'date'=>$parameters['date'],'date2'=>$parameters['date'],'id'=>$parameters['conversation']]);
            }
            $find = $this->pdo->prepare('SELECT * FROM telegram_assistant_messages WHERE connection_id=:connection AND business_connection_row_id=:business AND telegram_chat_id=:chat AND telegram_message_id=:message');
            $find->execute(['connection'=>$parameters['connection'],'business'=>$parameters['business'],'chat'=>$parameters['chat'],'message'=>$parameters['message']]);
            return ['created'=>$created,'message'=>(array)$find->fetch()];
        });
    }

    /** Decrypts content from a row returned by this repository. */
    public function messageContent(array $message): array
    {
        $decoded = json_decode(TelegramAssistantPrivacy::revealContent((int)$message['connection_id'],(string)$message['content_encrypted'],(string)$message['content_key']), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function editMessage(array $identity, string $text, string $editedAt): bool
    {
        $content = self::json(['text'=>$text]);
        $protected=TelegramAssistantPrivacy::protectContent((int)$identity['connection_id'],$content);
        $statement = $this->pdo->prepare('UPDATE telegram_assistant_messages SET content_encrypted=:content,content_key=:content_key,content_hash=:hash,expires_at=:expires,edited_at=:edited,updated_at=CURRENT_TIMESTAMP WHERE connection_id=:connection AND business_connection_row_id=:business AND telegram_chat_id=:chat AND telegram_message_id=:message AND deleted_at IS NULL');
        $statement->execute([
            'content'=>$protected['content_encrypted'],'content_key'=>$protected['content_key'],'expires'=>$protected['expires_at'],'hash'=>hash('sha256',$content),'edited'=>$editedAt,
            'connection'=>$identity['connection_id'],'business'=>$identity['business_connection_row_id'],
            'chat'=>$identity['telegram_chat_id'],'message'=>$identity['telegram_message_id'],
        ]);
        return $statement->rowCount() === 1;
    }

    public function markMessagesDeleted(int $connectionId, int $businessRowId, string $chatId, array $messageIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval',$messageIds), static fn(int $id): bool=>$id>0)));
        if ($ids === []) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare("UPDATE telegram_assistant_messages SET deleted_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE connection_id=? AND business_connection_row_id=? AND telegram_chat_id=? AND telegram_message_id IN ($placeholders) AND deleted_at IS NULL");
        $statement->execute(array_merge([$connectionId,$businessRowId,$chatId],$ids));
        return $statement->rowCount();
    }

    /** Opens one handoff and moves the conversation to handoff_pending. */
    public function requestHandoff(int $conversationId, string $reasonCode, string $reason = ''): array
    {
        $reasonCode = preg_match('/^[a-z][a-z0-9_]{2,59}$/', $reasonCode) === 1 ? $reasonCode : 'customer_request';
        return $this->transaction(function () use ($conversationId,$reasonCode,$reason): array {
            $find = $this->pdo->prepare("SELECT * FROM telegram_assistant_handoffs WHERE conversation_id=:conversation AND status IN ('requested','accepted') ORDER BY id DESC LIMIT 1");
            $find->execute(['conversation'=>$conversationId]);
            $row = $find->fetch();
            if (!is_array($row)) {
                $context = 'telegram.assistant-handoff.' . $conversationId;
                $sealed = trim($reason)==='' ? null : SecretStore::seal($reason,$context);
                $this->pdo->prepare("INSERT INTO telegram_assistant_handoffs(conversation_id,reason_code,reason_encrypted,status) VALUES(:conversation,:code,:reason,'requested')")
                    ->execute(['conversation'=>$conversationId,'code'=>$reasonCode,'reason'=>$sealed]);
                $find->execute(['conversation'=>$conversationId]);
                $row = $find->fetch();
            }
            $this->pdo->prepare("UPDATE telegram_assistant_conversations SET mode='handoff_pending',paused_until=NULL,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['id'=>$conversationId]);
            return (array)$row;
        });
    }

    /** Claims or resolves a handoff while keeping an auditable state. */
    public function setHandoffState(int $conversationId, string $status, ?int $operatorUserId = null): array
    {
        if (!in_array($status,['accepted','resolved','cancelled'],true)) throw new RuntimeException('وضعیت واگذاری معتبر نیست.');
        return $this->transaction(function () use ($conversationId,$status,$operatorUserId): array {
            $query=$this->pdo->prepare("SELECT * FROM telegram_assistant_handoffs WHERE conversation_id=:conversation AND status IN ('requested','accepted') ORDER BY id DESC LIMIT 1");
            $query->execute(['conversation'=>$conversationId]);
            $handoff=$query->fetch();
            if(!is_array($handoff)) throw new RuntimeException('واگذاری بازی پیدا نشد.');
            $accepted=$status==='accepted'?'CURRENT_TIMESTAMP':'accepted_at';
            $resolved=in_array($status,['resolved','cancelled'],true)?'CURRENT_TIMESTAMP':'resolved_at';
            $this->pdo->prepare("UPDATE telegram_assistant_handoffs SET status=:status,assigned_user_id=:user,accepted_at=$accepted,resolved_at=$resolved,updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['status'=>$status,'user'=>$operatorUserId,'id'=>$handoff['id']]);
            $mode=$status==='accepted'?'human':($status==='cancelled'?'bot':'closed');
            $this->pdo->prepare('UPDATE telegram_assistant_conversations SET mode=:mode,assigned_user_id=:user,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute(['mode'=>$mode,'user'=>$status==='accepted'?$operatorUserId:null,'id'=>$conversationId]);
            $query->execute(['conversation'=>$conversationId]);
            return ['status'=>$status,'conversation_id'=>$conversationId,'handoff_id'=>(int)$handoff['id']];
        });
    }

    public function conversationById(int $id): ?array
    {
        $query=$this->pdo->prepare('SELECT * FROM telegram_assistant_conversations WHERE id=:id');$query->execute(['id'=>$id]);$row=$query->fetch();
        return is_array($row)?$row:null;
    }

    public function setConversationMode(int $conversationId,string $mode,?int $assignedUserId=null,?string $pausedUntil=null): void
    {
        if(!in_array($mode,['bot','handoff_pending','human','closed'],true))throw new RuntimeException('حالت گفت‌وگو معتبر نیست.');
        $this->pdo->prepare('UPDATE telegram_assistant_conversations SET mode=:mode,assigned_user_id=:user,paused_until=:paused,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute(['mode'=>$mode,'user'=>$assignedUserId,'paused'=>$pausedUntil,'id'=>$conversationId]);
    }

    public function driver(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function utc(string $modifier='now'): string
    {
        return (new DateTimeImmutable($modifier,new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public static function timestamp(string $value): int
    {
        if(trim($value)==='')return 0;
        try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->getTimestamp();}catch(Throwable){return 0;}
    }

    public static function telegramId(string $value,bool $signed=true): string
    {
        $pattern=$signed?'/^-?[1-9][0-9]{0,19}$/':'/^[1-9][0-9]{0,19}$/';
        if(preg_match($pattern,$value)!==1)throw new RuntimeException('شناسهٔ Telegram معتبر نیست.');
        return $value;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private static function profileContext(int $connectionId): string{return 'telegram.assistant-profile.'.$connectionId;}
}
