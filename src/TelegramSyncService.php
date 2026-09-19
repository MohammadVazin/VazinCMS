<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

final class TelegramSyncService
{
    private const LOCALES = ['fa','ar','en','ru','tr','hy','kk','tg','zh'];

    public static function createConnection(array $input, int $userId): int
    {
        $name = trim((string)($input['name'] ?? ''));
        $chatId = trim((string)($input['chat_id'] ?? ''));
        $token = trim((string)($input['bot_token'] ?? ''));
        $syncMode = (string)($input['sync_mode'] ?? 'telegram_to_site');
        $incomingStatus = (string)($input['incoming_status'] ?? 'draft');
        $locale = (string)($input['locale'] ?? 'fa');
        $managerChat = trim((string)($input['manager_chat_id'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 190) throw new RuntimeException('نام اتصال تلگرام معتبر نیست.');
        if (preg_match('/^-?[1-9][0-9]{0,19}$/', $chatId) !== 1) throw new RuntimeException('شناسهٔ عددی کانال یا گروه معتبر نیست.');
        if ($managerChat !== '' && preg_match('/^-?[1-9][0-9]{0,19}$/', $managerChat) !== 1) throw new RuntimeException('شناسهٔ گفت‌وگوی مدیر معتبر نیست.');
        if (!TelegramBotApi::validToken($token)) throw new RuntimeException('Bot Token معتبر نیست.');
        if (!in_array($syncMode, ['telegram_to_site','site_to_telegram','bidirectional'], true)) throw new RuntimeException('حالت همگام‌سازی معتبر نیست.');
        if (!in_array($incomingStatus, ['draft','published'], true) || !in_array($locale, self::LOCALES, true)) throw new RuntimeException('وضعیت ورودی یا زبان معتبر نیست.');

        $webhookKey = bin2hex(random_bytes(16));
        $webhookSecret = bin2hex(random_bytes(32));
        $networkEnabled = DeliveryPolicy::telegramEnabled();
        $enabled = $networkEnabled && !empty($input['is_enabled']) ? 1 : 0;
        $statement = Database::connection()->prepare(
            'INSERT INTO telegram_connections(name,chat_id,encrypted_bot_token,webhook_key,encrypted_webhook_secret,sync_mode,incoming_status,locale,auto_publish_site,manager_chat_id,web_app_enabled,is_enabled,webhook_status,created_by) '
            . 'VALUES(:name,:chat,:token,:key,:secret,:mode,:incoming,:locale,:auto,:manager,:webapp,:enabled,:webhook_status,:user)'
        );
        $statement->execute([
            'name'=>$name,'chat'=>$chatId,
            'token'=>SecretStore::seal($token, self::tokenContext($webhookKey)),
            'key'=>$webhookKey,
            'secret'=>SecretStore::seal($webhookSecret, self::webhookContext($webhookKey)),
            'mode'=>$syncMode,'incoming'=>$incomingStatus,'locale'=>$locale,
            'auto'=>$networkEnabled && !empty($input['auto_publish_site'])?1:0,'manager'=>$managerChat,
            'webapp'=>$networkEnabled && !empty($input['web_app_enabled'])?1:0,'enabled'=>$enabled,
            'webhook_status'=>$enabled ? 'pending' : 'disabled','user'=>$userId,
        ]);
        $query = Database::connection()->prepare('SELECT id FROM telegram_connections WHERE webhook_key=:key');
        $query->execute(['key'=>$webhookKey]);
        return (int)$query->fetchColumn();
    }

    public static function updateConnection(int $id, array $input): void
    {
        $connection = self::connectionById($id);
        $syncMode = (string)($input['sync_mode'] ?? $connection['sync_mode']);
        $incomingStatus = (string)($input['incoming_status'] ?? $connection['incoming_status']);
        $locale = (string)($input['locale'] ?? $connection['locale']);
        $managerChat = trim((string)($input['manager_chat_id'] ?? $connection['manager_chat_id']));
        if (!in_array($syncMode, ['telegram_to_site','site_to_telegram','bidirectional'], true)
            || !in_array($incomingStatus, ['draft','published'], true) || !in_array($locale, self::LOCALES, true)) {
            throw new RuntimeException('تنظیم همگام‌سازی معتبر نیست.');
        }
        if ($managerChat !== '' && preg_match('/^-?[1-9][0-9]{0,19}$/', $managerChat) !== 1) throw new RuntimeException('شناسهٔ گفت‌وگوی مدیر معتبر نیست.');
        $networkEnabled = DeliveryPolicy::telegramEnabled();
        $enabled = $networkEnabled && !empty($input['is_enabled']) ? 1 : 0;
        $params = [
            'id'=>$id,'mode'=>$syncMode,'incoming'=>$incomingStatus,'locale'=>$locale,
            'auto'=>$networkEnabled && !empty($input['auto_publish_site'])?1:0,'manager'=>$managerChat,
            'webapp'=>$networkEnabled && !empty($input['web_app_enabled'])?1:0,'enabled'=>$enabled,
        ];
        $token = trim((string)($input['bot_token'] ?? ''));
        $tokenSql = '';
        if ($token !== '') {
            if (!TelegramBotApi::validToken($token)) throw new RuntimeException('Bot Token جدید معتبر نیست.');
            $tokenSql = ',encrypted_bot_token=:token';
            $params['token'] = SecretStore::seal($token, self::tokenContext((string)$connection['webhook_key']));
        }
        Database::connection()->prepare(
            "UPDATE telegram_connections SET sync_mode=:mode,incoming_status=:incoming,locale=:locale,auto_publish_site=:auto,manager_chat_id=:manager,web_app_enabled=:webapp,is_enabled=:enabled,webhook_status=CASE WHEN :enabled_status=0 THEN 'disabled' ELSE webhook_status END"
            . $tokenSql . ',updated_at=CURRENT_TIMESTAMP WHERE id=:id'
        )->execute($params + ['enabled_status'=>$enabled]);
    }

    public static function connectionById(int $id): array
    {
        if ($id < 1) throw new RuntimeException('اتصال تلگرام معتبر نیست.');
        $statement = Database::connection()->prepare('SELECT * FROM telegram_connections WHERE id=:id');
        $statement->execute(['id'=>$id]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new RuntimeException('اتصال تلگرام پیدا نشد.');
        return $row;
    }

    public static function connectionByWebhookKey(string $key): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1) throw new RuntimeException('مسیر Webhook معتبر نیست.');
        $statement = Database::connection()->prepare('SELECT * FROM telegram_connections WHERE webhook_key=:key AND is_enabled=1');
        $statement->execute(['key'=>$key]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new RuntimeException('Webhook فعال پیدا نشد.');
        return $row;
    }

    public static function connections(): array
    {
        return Database::connection()->query('SELECT id,name,bot_username,chat_id,webhook_key,sync_mode,incoming_status,locale,auto_publish_site,manager_chat_id,web_app_enabled,is_enabled,webhook_status,last_update_id,last_sync_at,last_error,created_at FROM telegram_connections ORDER BY id DESC')->fetchAll();
    }

    public static function botToken(array $connection): string
    {
        return SecretStore::open((string)$connection['encrypted_bot_token'], self::tokenContext((string)$connection['webhook_key']));
    }

    public static function webhookSecret(array $connection): string
    {
        return SecretStore::open((string)$connection['encrypted_webhook_secret'], self::webhookContext((string)$connection['webhook_key']));
    }

    public static function registerWebhook(int $connectionId): array
    {
        DeliveryPolicy::assertTelegramEnabled();
        $connection = self::connectionById($connectionId);
        if ((int)$connection['is_enabled'] !== 1) throw new RuntimeException('ابتدا اتصال Telegram را فعال کنید.');
        $appUrl = rtrim(trim((string)getenv('APP_URL')), '/');
        if (!self::publicHttpsUrl($appUrl)) throw new RuntimeException('APP_URL عمومی و HTTPS برای Webhook لازم است.');
        $api = new TelegramBotApi(self::botToken($connection));
        try {
            $identity = $api->call('getMe');
            self::assertWebhookIdentityCompatible($connectionId,$identity);
            $api->call('setWebhook', [
                'url'=>$appUrl . '/telegram/webhook/' . $connection['webhook_key'],
                'secret_token'=>self::webhookSecret($connection),
                'allowed_updates'=>[
                    'channel_post','edited_channel_post','message','callback_query',
                    'business_connection','business_message','edited_business_message','deleted_business_messages',
                ],
                'drop_pending_updates'=>false,
                'max_connections'=>20,
            ]);
            if ((int)$connection['web_app_enabled'] === 1) {
                $api->call('setChatMenuButton', ['menu_button'=>[
                    'type'=>'web_app','text'=>match ((string)$connection['locale']) {'ru'=>'Помощник и запись','en'=>'Assistant & booking',default=>'دستیار و رزرو'},
                    'web_app'=>['url'=>$appUrl . '/telegram/assistant/' . $connection['webhook_key']],
                ]]);
            }
            Database::connection()->prepare("UPDATE telegram_connections SET bot_username=:username,webhook_status='active',last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['username'=>(string)($identity['username']??''),'id'=>$connectionId]);
            return $identity;
        } catch (Throwable $error) {
            Database::connection()->prepare("UPDATE telegram_connections SET webhook_status='failed',last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['error'=>mb_substr($error->getMessage(),0,1000),'id'=>$connectionId]);
            throw $error;
        }
    }

    /** getMe preflight; channel-only connections remain compatible. */
    public static function assertWebhookIdentityCompatible(int $connectionId,array $identity): void
    {
        self::connectionById($connectionId);
        $assistantProfile=(new TelegramAssistantRepository())->profileByConnection($connectionId);
        if($assistantProfile!==null&&($identity['can_connect_to_business']??false)!==true){
            throw new RuntimeException('این ربات امکان اتصال به Telegram Business را ندارد؛ Secretary Mode را در BotFather فعال یا ربات مناسب را انتخاب کنید.');
        }
    }

    public static function saveAdminMapping(int $connectionId, string $telegramUserId, int $cmsUserId): void
    {
        self::connectionById($connectionId);
        if (preg_match('/^[1-9][0-9]{0,18}$/', $telegramUserId) !== 1 || $cmsUserId < 1) throw new RuntimeException('شناسهٔ مدیر Telegram یا کاربر CMS معتبر نیست.');
        $statement = Database::connection()->prepare('SELECT 1 FROM users WHERE id=:id AND status=\'active\' AND role IN (\'owner\',\'admin\')');
        $statement->execute(['id'=>$cmsUserId]);
        if (!$statement->fetchColumn()) throw new RuntimeException('مدیر فعال CMS پیدا نشد.');
        Database::connection()->prepare(
            'INSERT INTO telegram_admins(connection_id,telegram_user_id,cms_user_id,is_enabled) VALUES(:connection,:telegram,:cms,1) '
            . 'ON CONFLICT(connection_id,telegram_user_id) DO UPDATE SET cms_user_id=:cms2,is_enabled=1'
        )->execute(['connection'=>$connectionId,'telegram'=>$telegramUserId,'cms'=>$cmsUserId,'cms2'=>$cmsUserId]);
    }

    public static function receiveWebhook(array $connection, array $update): array
    {
        DeliveryPolicy::assertTelegramEnabled();
        $updateId = filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($updateId) || $updateId < 0) throw new RuntimeException('update_id تلگرام معتبر نیست.');
        $type = 'unknown';
        foreach ([
            'channel_post','edited_channel_post','message','callback_query',
            'business_connection','business_message','edited_business_message','deleted_business_messages',
        ] as $candidate) {
            if (isset($update[$candidate]) && is_array($update[$candidate])) { $type = $candidate; break; }
        }
        $businessUpdate = in_array($type, ['business_connection','business_message','edited_business_message','deleted_business_messages'], true)
            || ($type === 'callback_query' && isset($update['callback_query']['message']['business_connection_id']));
        $json = json_encode(
            $businessUpdate ? self::redactedBusinessUpdate($type, $update, $updateId)
                : ($type === 'message' && TravelAlertService::isConsentCommand($update['message'])
                    ? TravelAlertService::redactedConsentUpdate($update, $updateId)
                    : $update),
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
        );
        $insert = Database::connection()->prepare(
            'INSERT INTO telegram_updates(connection_id,update_id,update_type,payload_json) VALUES(:connection,:update,:type,:payload) '
            . 'ON CONFLICT(connection_id,update_id) DO NOTHING'
        );
        $insert->execute(['connection'=>$connection['id'],'update'=>$updateId,'type'=>$type,'payload'=>$json]);
        if ($insert->rowCount() === 0) {
            $retry = Database::connection()->prepare(
                "UPDATE telegram_updates SET status='received',update_type=:type,payload_json=:payload,error_message=NULL,processed_at=NULL "
                . "WHERE connection_id=:connection AND update_id=:update AND status='failed'"
            );
            $retry->execute(['type'=>$type,'payload'=>$json,'connection'=>$connection['id'],'update'=>$updateId]);
            if ($retry->rowCount() !== 1) return ['status'=>'duplicate','update_id'=>$updateId];
        }
        try {
            if (in_array($type, ['channel_post','edited_channel_post'], true)) {
                $message = $update[$type];
                $chatId = (string)($message['chat']['id'] ?? '');
                if (!hash_equals((string)$connection['chat_id'], $chatId)) throw new RuntimeException('این رویداد متعلق به کانال تنظیم‌شده نیست.');
                if (!in_array((string)$connection['sync_mode'], ['telegram_to_site','bidirectional'], true)) {
                    $result = ['status'=>'ignored','reason'=>'incoming_disabled'];
                } else {
                    $result = self::syncChannelMessage($connection, $message, $updateId, $json);
                }
            } elseif ($type === 'message') {
                $result = TravelAlertService::handleIncomingMessage($connection, $update['message'])
                    ?? self::handleManagerCommand($connection, $update['message']);
            } elseif ($type === 'business_connection') {
                if (!DeliveryPolicy::telegramBusinessEnabled()) {
                    $result = ['status'=>'ignored','reason'=>'telegram_business_disabled'];
                } else {
                    $assistant = self::assistantService();
                    $result = $assistant->handleBusinessConnection((int)$connection['id'], $update['business_connection']);
                    $result = ['status'=>'connection_recorded','authorization_status'=>(string)($result['authorization_status']??'pending'),'is_enabled'=>(int)($result['is_enabled']??0)];
                }
            } elseif (in_array($type, ['business_message','edited_business_message','deleted_business_messages'], true)) {
                if (!DeliveryPolicy::telegramBusinessEnabled()) {
                    $result = ['status'=>'ignored','reason'=>'telegram_business_disabled'];
                } elseif (!(new TelegramAssistantRepository())->profileByConnection((int)$connection['id'])) {
                    $result = ['status'=>'ignored','reason'=>'assistant_not_configured'];
                } else {
                    $assistant = self::assistantService();
                    try {
                        $result = match ($type) {
                            'business_message'=>$assistant->handleBusinessMessage((int)$connection['id'],$update[$type],$updateId),
                            'edited_business_message'=>$assistant->handleEditedBusinessMessage((int)$connection['id'],$update[$type],$updateId),
                            default=>$assistant->handleDeletedBusinessMessages((int)$connection['id'],$update[$type]),
                        };
                    } catch (RuntimeException $error) {
                        if (!str_contains($error->getMessage(),'BusinessConnection مجاز و فعال پیدا نشد')) throw $error;
                        $result = ['status'=>'ignored','reason'=>'business_connection_inactive'];
                    }
                }
            } elseif ($type === 'callback_query' && isset($update['callback_query']['message']['business_connection_id'])) {
                if (!DeliveryPolicy::telegramBusinessEnabled()) {
                    $result = ['status'=>'ignored','reason'=>'telegram_business_disabled'];
                } else {
                    $result = self::assistantService()->handleCallbackQuery((int)$connection['id'],$update['callback_query']);
                }
            } else {
                $result = ['status'=>'ignored','reason'=>'unsupported_update'];
            }
            $state = ($result['status'] ?? '') === 'ignored' ? 'ignored' : 'processed';
            Database::connection()->prepare('UPDATE telegram_updates SET status=:status,processed_at=CURRENT_TIMESTAMP WHERE connection_id=:connection AND update_id=:update')
                ->execute(['status'=>$state,'connection'=>$connection['id'],'update'=>$updateId]);
            Database::connection()->prepare('UPDATE telegram_connections SET last_update_id=CASE WHEN last_update_id IS NULL OR last_update_id<:update THEN :update2 ELSE last_update_id END,last_sync_at=CURRENT_TIMESTAMP,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute(['update'=>$updateId,'update2'=>$updateId,'id'=>$connection['id']]);
            return $result + ['update_id'=>$updateId];
        } catch (Throwable $error) {
            $message = mb_substr($error->getMessage(), 0, 1000);
            Database::connection()->prepare("UPDATE telegram_updates SET status='failed',error_message=:error,processed_at=CURRENT_TIMESTAMP WHERE connection_id=:connection AND update_id=:update")
                ->execute(['error'=>$message,'connection'=>$connection['id'],'update'=>$updateId]);
            Database::connection()->prepare('UPDATE telegram_connections SET last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute(['error'=>$message,'id'=>$connection['id']]);
            throw $error;
        }
    }

    public static function syncChannelMessage(array $connection, array $message, ?int $updateId = null, ?string $rawPayload = null): array
    {
        $messageId = filter_var($message['message_id'] ?? null, FILTER_VALIDATE_INT);
        $timestamp = filter_var($message['date'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($messageId) || $messageId < 1 || !is_int($timestamp) || $timestamp < 1) throw new RuntimeException('شناسه یا تاریخ پیام تلگرام معتبر نیست.');
        $text = trim((string)($message['text'] ?? $message['caption'] ?? ''));
        if ($text === '') $text = self::mediaPlaceholder($message, $messageId, (string)($connection['locale'] ?? 'fa'));
        $title = self::titleFromText($text, $messageId);
        $publishedAt = gmdate('Y-m-d H:i:s', $timestamp);
        $editTimestamp = filter_var($message['edit_date'] ?? null, FILTER_VALIDATE_INT);
        $updatedAt = is_int($editTimestamp) && $editTimestamp > 0 ? gmdate('Y-m-d H:i:s', $editTimestamp) : $publishedAt;
        $sourceRef = 'connection:' . $connection['id'] . ':message:' . $messageId;
        $slug = 'telegram-' . $connection['id'] . '-' . $messageId;
        $status = in_array((string)$connection['incoming_status'], ['draft','published'], true) ? (string)$connection['incoming_status'] : 'draft';
        $hash = hash('sha256', $title . "\0" . $text . "\0" . $publishedAt);
        $payload = $rawPayload ?? json_encode($message, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $pdo = Database::connection();
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sqlite = $driver === 'sqlite';
        if ($sqlite) $pdo->exec('BEGIN IMMEDIATE'); else $pdo->beginTransaction();
        try {
            $find = $pdo->prepare("SELECT * FROM cms_pages WHERE source_provider='telegram' AND source_ref=:ref LIMIT 1");
            $find->execute(['ref'=>$sourceRef]);
            $page = $find->fetch();
            $description = mb_substr(preg_replace('/\s+/u',' ',strip_tags($text)) ?? $text, 0, 180);
            $record = [
                'slug'=>$slug,'locale'=>$connection['locale'],'title'=>$title,'body'=>$text,'status'=>$status,
                'author'=>$connection['created_by']?:null,'published'=>$publishedAt,'updated'=>$updatedAt,
                'ref'=>$sourceRef,'meta_title'=>mb_substr($title,0,70),'meta_description'=>$description,
                'og_title'=>mb_substr($title,0,255),'og_description'=>$description,
            ];
            $created = false;
            if (is_array($page)) {
                $record['id'] = $page['id'];
                $pdo->prepare("UPDATE cms_pages SET slug=:slug,locale=:locale,title=:title,body=:body,status=:status,content_type='post',author_id=COALESCE(author_id,:author),published_at=:published,source_provider='telegram',source_ref=:ref,meta_title=:meta_title,meta_description=:meta_description,og_title=:og_title,og_description=:og_description,schema_type='Article',updated_at=:updated WHERE id=:id")
                    ->execute($record);
                $pageId = (int)$page['id'];
            } else {
                $pdo->prepare("INSERT INTO cms_pages(slug,locale,title,body,status,is_home,author_id,meta_title,meta_description,canonical_url,featured_image,content_type,published_at,source_provider,source_ref,robots_index,robots_follow,og_title,og_description,schema_type,created_at,updated_at) VALUES(:slug,:locale,:title,:body,:status,0,:author,:meta_title,:meta_description,'','', 'post',:published,'telegram',:ref,1,1,:og_title,:og_description,'Article',:published,:updated)")
                    ->execute($record);
                $query = $pdo->prepare("SELECT id FROM cms_pages WHERE source_provider='telegram' AND source_ref=:ref");
                $query->execute(['ref'=>$sourceRef]);
                $pageId = (int)$query->fetchColumn();
                $created = true;
            }
            $pdo->prepare(
                "INSERT INTO telegram_messages(connection_id,telegram_message_id,telegram_update_id,cms_page_id,direction,status,message_date,edit_date,content_hash,payload_json) VALUES(:connection,:message,:update,:page,'incoming','synced',:date,:edit,:hash,:payload) "
                . "ON CONFLICT(connection_id,telegram_message_id) DO UPDATE SET telegram_update_id=:update2,cms_page_id=:page2,status='synced',message_date=:date2,edit_date=:edit2,content_hash=:hash2,payload_json=:payload2,last_error=NULL,updated_at=CURRENT_TIMESTAMP"
            )->execute([
                'connection'=>$connection['id'],'message'=>$messageId,'update'=>$updateId,'page'=>$pageId,'date'=>$publishedAt,'edit'=>is_int($editTimestamp)?$updatedAt:null,'hash'=>$hash,'payload'=>$payload,
                'update2'=>$updateId,'page2'=>$pageId,'date2'=>$publishedAt,'edit2'=>is_int($editTimestamp)?$updatedAt:null,'hash2'=>$hash,'payload2'=>$payload,
            ]);
            if ($sqlite) $pdo->exec('COMMIT'); else $pdo->commit();
            ExtensionRuntime::emit('content.imported',['page_id'=>$pageId,'provider'=>'telegram','connection_id'=>(int)$connection['id'],'created'=>$created]);
            return ['status'=>'synced','page_id'=>$pageId,'created'=>$created,'message_id'=>$messageId];
        } catch (Throwable $error) {
            try {
                if ($sqlite) $pdo->exec('ROLLBACK');
                elseif ($pdo->inTransaction()) $pdo->rollBack();
            } catch (Throwable) {}
            throw $error;
        }
    }

    public static function queuePage(int $pageId): int
    {
        if ($pageId < 1 || !DeliveryPolicy::telegramEnabled()) return 0;
        $pdo = Database::connection();
        $query = $pdo->prepare("SELECT * FROM cms_pages WHERE id=:id AND status='published'");
        $query->execute(['id'=>$pageId]);
        $page = $query->fetch();
        if (!is_array($page) || (string)($page['source_provider']??'') === 'telegram') return 0;
        $connections = $pdo->query("SELECT * FROM telegram_connections WHERE is_enabled=1 AND auto_publish_site=1 AND sync_mode IN ('site_to_telegram','bidirectional') ORDER BY id")->fetchAll();
        $contentHash = hash('sha256', (string)$page['title'] . "\0" . (string)$page['body'] . "\0" . (string)$page['updated_at']);
        $count = 0;
        foreach ($connections as $connection) {
            $mapped = $pdo->prepare('SELECT telegram_message_id FROM telegram_messages WHERE connection_id=:connection AND cms_page_id=:page ORDER BY id DESC LIMIT 1');
            $mapped->execute(['connection'=>$connection['id'],'page'=>$pageId]);
            $telegramMessageId = $mapped->fetchColumn();
            $action = $telegramMessageId ? 'edit_page' : 'send_page';
            $dedupe = 'page-' . $connection['id'] . '-' . $pageId . '-' . $contentHash;
            $insert = $pdo->prepare('INSERT INTO telegram_outbox(connection_id,cms_page_id,action,payload_json,dedupe_key,telegram_message_id) VALUES(:connection,:page,:action,:payload,:dedupe,:message) ON CONFLICT(dedupe_key) DO NOTHING');
            $insert->execute([
                'connection'=>$connection['id'],'page'=>$pageId,'action'=>$action,
                'payload'=>json_encode(['content_hash'=>$contentHash],JSON_UNESCAPED_SLASHES),
                'dedupe'=>$dedupe,'message'=>$telegramMessageId?:null,
            ]);
            $count += $insert->rowCount();
        }
        return $count;
    }

    public static function queueManagerNotice(int $connectionId, string $chatId, string $text, string $dedupeSeed): bool
    {
        if (!DeliveryPolicy::telegramEnabled() || preg_match('/^-?[1-9][0-9]{0,19}$/', $chatId) !== 1 || trim($text) === '') return false;
        $connection = self::connectionById($connectionId);
        if ((int)$connection['is_enabled'] !== 1) return false;
        $dedupe = 'manager-' . $connectionId . '-' . hash('sha256', $dedupeSeed);
        $statement = Database::connection()->prepare("INSERT INTO telegram_outbox(connection_id,action,payload_json,dedupe_key) VALUES(:connection,'manager_notice',:payload,:dedupe) ON CONFLICT(dedupe_key) DO NOTHING");
        $statement->execute([
            'connection'=>$connectionId,
            'payload'=>json_encode(['chat_id'=>$chatId,'text'=>mb_substr($text,0,3900)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'dedupe'=>$dedupe,
        ]);
        return $statement->rowCount() === 1;
    }

    public static function notifyManagers(array $advisory): int
    {
        if (!DeliveryPolicy::telegramEnabled()) return 0;
        $connections = Database::connection()->query("SELECT id,manager_chat_id FROM telegram_connections WHERE is_enabled=1 AND manager_chat_id<>''")->fetchAll();
        $text = 'هشدار مدیریتی: ' . (string)($advisory['title']??'بازبینی محتوا') . "\n" . (string)($advisory['message']??'') . "\nصفحه: " . (int)($advisory['page_id']??0);
        $count = 0;
        foreach ($connections as $connection) {
            $count += self::queueManagerNotice((int)$connection['id'], (string)$connection['manager_chat_id'], $text, 'advisory-' . (int)($advisory['page_id']??0) . '-' . ($advisory['fingerprint']??hash('sha256',$text))) ? 1 : 0;
        }
        return $count;
    }

    public static function processOutbox(int $limit = 10): array
    {
        if (!DeliveryPolicy::telegramEnabled()) return ['status'=>'disabled','processed'=>0,'sent'=>0,'failed'=>0];
        $pdo = Database::connection();
        $limit = max(1, min(50, $limit));
        $rows = $pdo->query("SELECT * FROM telegram_outbox WHERE status='pending' AND next_attempt_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT " . $limit)->fetchAll();
        $result = ['processed'=>0,'sent'=>0,'failed'=>0];
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(16));
            $lock = $pdo->prepare("UPDATE telegram_outbox SET status='processing',locked_at=CURRENT_TIMESTAMP,lock_token=:token,attempts=attempts+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='pending'");
            $lock->execute(['token'=>$token,'id'=>$row['id']]);
            if ($lock->rowCount() !== 1) continue;
            $result['processed']++;
            try {
                $connection = self::connectionById((int)$row['connection_id']);
                if ((int)$connection['is_enabled'] !== 1) throw new RuntimeException('اتصال تلگرام غیرفعال است.');
                $api = new TelegramBotApi(self::botToken($connection));
                $payload = json_decode((string)$row['payload_json'], true) ?: [];
                $telegramId = null;
                if ($row['action'] === 'manager_notice') {
                    $response = $api->call('sendMessage', ['chat_id'=>(string)$payload['chat_id'],'text'=>(string)$payload['text'],'disable_web_page_preview'=>true]);
                    $telegramId = isset($response['message_id']) ? (int)$response['message_id'] : null;
                } else {
                    $pageQuery = $pdo->prepare("SELECT * FROM cms_pages WHERE id=:id AND status='published'");
                    $pageQuery->execute(['id'=>$row['cms_page_id']]);
                    $page = $pageQuery->fetch();
                    if (!is_array($page)) throw new RuntimeException('صفحهٔ صف انتشار دیگر منتشرشده نیست.');
                    $text = self::outgoingText($page);
                    if ($row['action'] === 'edit_page' && !empty($row['telegram_message_id'])) {
                        try {
                            $response = $api->call('editMessageText', ['chat_id'=>$connection['chat_id'],'message_id'=>(int)$row['telegram_message_id'],'text'=>$text,'disable_web_page_preview'=>false]);
                        } catch (RuntimeException $error) {
                            if (!str_contains(mb_strtolower($error->getMessage()), 'message is not modified')) throw $error;
                            $response = ['message_id'=>(int)$row['telegram_message_id'],'date'=>time()];
                        }
                    } else {
                        $response = $api->call('sendMessage', ['chat_id'=>$connection['chat_id'],'text'=>$text,'disable_web_page_preview'=>false]);
                    }
                    $telegramId = (int)($response['message_id']??$row['telegram_message_id']??0);
                    if ($telegramId < 1) throw new RuntimeException('شناسهٔ پیام منتشرشده دریافت نشد.');
                    $messageDate = gmdate('Y-m-d H:i:s', (int)($response['date']??time()));
                    $contentHash = hash('sha256', $text);
                    $pdo->prepare(
                        "INSERT INTO telegram_messages(connection_id,telegram_message_id,cms_page_id,direction,status,message_date,content_hash,payload_json) VALUES(:connection,:message,:page,'outgoing','published',:date,:hash,:payload) "
                        . "ON CONFLICT(connection_id,telegram_message_id) DO UPDATE SET cms_page_id=:page2,status='published',content_hash=:hash2,payload_json=:payload2,last_error=NULL,updated_at=CURRENT_TIMESTAMP"
                    )->execute([
                        'connection'=>$connection['id'],'message'=>$telegramId,'page'=>$page['id'],'date'=>$messageDate,'hash'=>$contentHash,
                        'payload'=>json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                        'page2'=>$page['id'],'hash2'=>$contentHash,'payload2'=>json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    ]);
                }
                $pdo->prepare("UPDATE telegram_outbox SET status='sent',telegram_message_id=:message,locked_at=NULL,lock_token=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND lock_token=:token")
                    ->execute(['message'=>$telegramId,'id'=>$row['id'],'token'=>$token]);
                $result['sent']++;
            } catch (Throwable $error) {
                $attempt = (int)$row['attempts'] + 1;
                $terminal = $attempt >= (int)$row['max_attempts'];
                $next = date('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** min(6, $attempt))));
                $pdo->prepare("UPDATE telegram_outbox SET status=:status,next_attempt_at=:next,locked_at=NULL,lock_token=NULL,last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND lock_token=:token")
                    ->execute(['status'=>$terminal?'failed':'pending','next'=>$next,'error'=>mb_substr($error->getMessage(),0,1000),'id'=>$row['id'],'token'=>$token]);
                $result['failed']++;
            }
        }
        return $result;
    }

    private static function handleManagerCommand(array $connection, array $message): array
    {
        $fromId = (string)($message['from']['id']??'');
        $chatId = (string)($message['chat']['id']??'');
        $text = trim((string)($message['text']??''));
        if ($fromId === '' || $chatId === '' || $text === '' || !str_starts_with($text, '/')) return ['status'=>'ignored','reason'=>'not_a_command'];
        $managerChat = (string)$connection['manager_chat_id'];
        $authorized = $managerChat !== '' && $chatId === $managerChat && $fromId === $managerChat;
        if (!$authorized) {
            $query = Database::connection()->prepare('SELECT 1 FROM telegram_admins WHERE connection_id=:connection AND telegram_user_id=:telegram AND is_enabled=1');
            $query->execute(['connection'=>$connection['id'],'telegram'=>$fromId]);
            $authorized = (bool)$query->fetchColumn();
        }
        if (!$authorized) return ['status'=>'ignored','reason'=>'unauthorized_manager'];
        $command = strtolower(explode('@', preg_split('/\s+/', $text)[0], 2)[0]);
        $pdo = Database::connection();
        if ($command === '/warnings') {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM content_advisories WHERE status='open'")->fetchColumn();
            $reply = $count . ' هشدار محتوایی باز در پنل مدیریت وجود دارد.';
        } elseif ($command === '/status') {
            $pending = (int)$pdo->query("SELECT COUNT(*) FROM telegram_outbox WHERE status='pending'")->fetchColumn();
            $reply = 'اتصال فعال است. صف در انتظار: ' . $pending . ' مورد.';
        } elseif ($command === '/sync') {
            $reply = 'همگام‌سازی رویدادهای جدید فعال است. برای تاریخچهٔ کامل، خروجی JSON کانال را در پنل وارد کنید.';
        } else {
            $reply = "دستورها: /status ، /warnings ، /sync";
        }
        self::queueManagerNotice((int)$connection['id'], $chatId, $reply, 'command-' . ($message['message_id']??'') . '-' . $command);
        return ['status'=>'queued','command'=>$command];
    }

    private static function outgoingText(array $page): string
    {
        $base = rtrim((string)getenv('APP_URL'), '/');
        $url = $base . '/' . rawurlencode((string)$page['locale']) . '/page/' . rawurlencode((string)$page['slug']);
        $suffix = $base !== '' ? "\n\n" . $url : '';
        $maximum = max(100, 4096 - mb_strlen($suffix));
        return mb_substr(trim((string)$page['title']) . "\n\n" . trim((string)$page['body']), 0, $maximum) . $suffix;
    }

    private static function assistantService(): TelegramAssistantService
    {
        $repository = new TelegramAssistantRepository();
        $business = new TelegramBusinessService($repository);
        $outbox = new TelegramAssistantOutbox(
            $repository,
            $business,
            static fn(array $context): bool => DeliveryPolicy::telegramAssistantDeliveryEnabled()
        );
        return new TelegramAssistantService(
            $repository,
            $business,
            $outbox,
            static fn(array $context): bool => DeliveryPolicy::telegramAssistantAutoreplyEnabled(),
            static function(array $context): bool {
                $profileId = (int)($context['profile_id']??0);
                $chatId = (string)($context['chat_id']??'');
                if ($profileId < 1 || $chatId === '') return false;
                return TelegramAssistantRateLimiter::consume('conversation', (string)$profileId, $chatId, 12, 60)['allowed'];
            }
        );
    }

    /** Business text and customer identifiers must never enter telegram_updates in plaintext. */
    private static function redactedBusinessUpdate(string $type, array $update, int $updateId): array
    {
        $event = is_array($update[$type]??null) ? $update[$type] : [];
        $businessId = (string)($event['business_connection_id']??$event['id']??$event['message']['business_connection_id']??'');
        $chatId = (string)($event['chat']['id']??$event['message']['chat']['id']??'');
        $redact = static fn(string $value, string $label): string => $value === '' ? '' : TelegramAssistantPrivacy::redactForLog($value,$label);
        return [
            'update_id'=>$updateId,
            'update_type'=>$type,
            'payload_redacted'=>true,
            'business_connection'=>$redact($businessId,'business-id'),
            'chat'=>$redact($chatId,'chat-id'),
            'message_id'=>(int)($event['message_id']??$event['message']['message_id']??0),
            'deleted_message_count'=>is_array($event['message_ids']??null)?count($event['message_ids']):0,
        ];
    }

    private static function titleFromText(string $text, int $messageId): string
    {
        $line = trim((string)(preg_split('/\R/u', $text)[0] ?? ''));
        $line = preg_replace('/\s+/u', ' ', strip_tags($line)) ?? $line;
        return $line !== '' ? mb_substr($line, 0, 190) : 'پست تلگرام ' . $messageId;
    }

    private static function mediaPlaceholder(array $message, int $messageId, string $locale = 'fa'): string
    {
        $labels = match ($locale) {
            'ru' => ['photo'=>'Изображение','video'=>'Видео','document'=>'Документ','audio'=>'Аудио','voice'=>'Голосовое сообщение','animation'=>'Анимация','sticker'=>'Стикер','poll'=>'Опрос'],
            'en' => ['photo'=>'Image','video'=>'Video','document'=>'Document','audio'=>'Audio','voice'=>'Voice message','animation'=>'Animation','sticker'=>'Sticker','poll'=>'Poll'],
            default => ['photo'=>'تصویر','video'=>'ویدئو','document'=>'سند','audio'=>'صدا','voice'=>'پیام صوتی','animation'=>'پویانمایی','sticker'=>'استیکر','poll'=>'نظرسنجی'],
        };
        $mediaType = mb_strtolower(trim((string)($message['media_type'] ?? '')));
        if ($mediaType !== '') {
            $key = str_contains($mediaType, 'voice') ? 'voice'
                : (str_contains($mediaType, 'video') ? 'video'
                : (str_contains($mediaType, 'audio') ? 'audio'
                : (str_contains($mediaType, 'animation') ? 'animation'
                : (str_contains($mediaType, 'sticker') ? 'sticker' : 'document'))));
            return '[' . $labels[$key] . ' Telegram · ' . self::messageWord($locale) . ' ' . $messageId . ']';
        }
        $telegramName = in_array($locale, ['ru','en'], true) ? 'Telegram' : 'تلگرام';
        foreach ($labels as $key=>$label) {
            if (array_key_exists($key, $message)) return '[' . $label . ' ' . $telegramName . ' · ' . self::messageWord($locale) . ' ' . $messageId . ']';
        }
        return match ($locale) {
            'ru' => '[Публикация Telegram · сообщение ' . $messageId . ']',
            'en' => '[Telegram post · message ' . $messageId . ']',
            default => '[پست تلگرام · پیام ' . $messageId . ']',
        };
    }

    private static function messageWord(string $locale): string
    {
        return match ($locale) { 'ru'=>'сообщение', 'en'=>'message', default=>'پیام' };
    }

    private static function tokenContext(string $webhookKey): string { return 'telegram.bot-token.' . $webhookKey; }
    private static function webhookContext(string $webhookKey): string { return 'telegram.webhook-secret.' . $webhookKey; }

    private static function publicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme']??'')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host = strtolower((string)$parts['host']);
        return $host !== 'localhost' && !str_ends_with($host, '.local');
    }
}
