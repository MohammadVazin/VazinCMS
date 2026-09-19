<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;
use Throwable;

/**
 * Consent-first notifications for the Travel and Visa white-label verticals.
 *
 * The CMS installation is the tenant boundary. This service deliberately
 * stores only a short case reference and an authenticated destination URL in
 * the encrypted outbox payload: never traveller data, passport details,
 * uploaded files, internal notes, or customer-visible message bodies.
 */
final class TravelAlertService
{
    private const PENDING_TTL_SECONDS = 86_400;
    private const OUTBOX_TTL_SECONDS = 2_592_000;
    private const CUSTOMER_SOURCE = 'travel_order';
    private const MANAGER_SOURCE = 'cms_user';

    /** @return array{available:bool,status:string,url?:string} */
    public static function customerOptIn(array $order): array
    {
        if (!DeliveryPolicy::telegramAlertsEnabled() || (int)($order['id'] ?? 0) < 1) {
            return ['available'=>false,'status'=>'delivery_disabled'];
        }
        $connection = self::activeConnection();
        if ($connection === null) return ['available'=>false,'status'=>'connection_unavailable'];
        return self::provisionOptIn(
            $connection,
            'customer',
            self::CUSTOMER_SOURCE,
            (int)$order['id'],
            self::locale((string)($order['locale'] ?? 'fa')),
            ['visa_case_updates']
        );
    }

    /** @return array{available:bool,status:string,url?:string} */
    public static function managerOptIn(int $connectionId, int $cmsUserId, string $locale = 'fa'): array
    {
        if (!DeliveryPolicy::telegramAlertsEnabled()) {
            return ['available'=>false,'status'=>'delivery_disabled'];
        }
        if ($cmsUserId < 1) throw new RuntimeException('کاربر مدیر برای فعال‌سازی هشدار معتبر نیست.');
        $connection = TelegramSyncService::connectionById($connectionId);
        self::assertActiveConnection($connection);
        return self::provisionOptIn(
            $connection,
            'manager',
            self::MANAGER_SOURCE,
            $cmsUserId,
            self::locale($locale),
            ['visa_case_updates','travel_operations']
        );
    }

    /**
     * Handle only consent commands. Other commands retain the existing
     * manager-command behaviour in TelegramSyncService.
     *
     * @return array<string,mixed>|null
     */
    public static function handleIncomingMessage(array $connection, array $message): ?array
    {
        $text = trim((string)($message['text'] ?? ''));
        $chatId = (string)($message['chat']['id'] ?? '');
        $userId = (string)($message['from']['id'] ?? '');
        if (!self::validTelegramId($chatId) || !self::validTelegramId($userId)) return null;

        if (preg_match('/^\\/stop(?:@[A-Za-z0-9_]{5,32})?\\s*$/i', $text) === 1) {
            $subscriptions = Database::connection()->prepare(
                "SELECT id FROM telegram_alert_subscriptions WHERE connection_id=:connection AND telegram_chat_id=:chat "
                . "AND status IN ('pending','opted_in','paused')"
            );
            $subscriptions->execute(['connection'=>(int)$connection['id'],'chat'=>$chatId]);
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $subscriptions->fetchAll());
            if ($ids !== []) {
                Database::connection()->prepare(
                    "UPDATE telegram_alert_subscriptions SET status='revoked',revoked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP "
                    . "WHERE connection_id=:connection AND telegram_chat_id=:chat AND status IN ('pending','opted_in','paused')"
                )->execute(['connection'=>(int)$connection['id'],'chat'=>$chatId]);
                self::cancelSubscriptions($ids);
            }
            return ['status'=>'opted_out','revoked'=>count($ids)];
        }

        if (preg_match('/^\\/start(?:@[A-Za-z0-9_]{5,32})?\\s+(va_[a-f0-9]{48})\\s*$/i', $text, $match) !== 1) {
            return null;
        }
        if (!DeliveryPolicy::telegramAlertsEnabled()) {
            return ['status'=>'ignored','reason'=>'alert_delivery_disabled'];
        }
        $tokenHash = hash('sha256', strtolower($match[1]));
        $query = Database::connection()->prepare(
            "SELECT * FROM telegram_alert_subscriptions WHERE connection_id=:connection AND consent_token_hash=:token "
            . "AND status='pending' AND consent_expires_at>CURRENT_TIMESTAMP LIMIT 1"
        );
        $query->execute(['connection'=>(int)$connection['id'],'token'=>$tokenHash]);
        $subscription = $query->fetch();
        if (!is_array($subscription)) return ['status'=>'ignored','reason'=>'consent_token_invalid'];

        $updated = Database::connection()->prepare(
            "UPDATE telegram_alert_subscriptions SET telegram_chat_id=:chat,telegram_user_id=:user,status='opted_in',"
            . "consent_token_hash=NULL,consent_expires_at=NULL,consented_at=CURRENT_TIMESTAMP,revoked_at=NULL,updated_at=CURRENT_TIMESTAMP "
            . "WHERE id=:id AND status='pending' AND consent_token_hash=:token"
        );
        $updated->execute(['chat'=>$chatId,'user'=>$userId,'id'=>$subscription['id'],'token'=>$tokenHash]);
        if ($updated->rowCount() !== 1) return ['status'=>'duplicate'];

        $subscription['telegram_chat_id'] = $chatId;
        $subscription['telegram_user_id'] = $userId;
        $subscription['status'] = 'opted_in';
        self::queueSubscriptionText(
            $subscription,
            'subscription_confirmed',
            'consent-' . (int)$subscription['id'],
            self::confirmationText((string)$subscription['locale'])
        );
        return ['status'=>'opted_in','recipient_type'=>(string)$subscription['recipient_type']];
    }

    public static function isConsentCommand(array $message): bool
    {
        $text = trim((string)($message['text'] ?? ''));
        return preg_match('/^\\/(?:stop|start)(?:@[A-Za-z0-9_]{5,32})?(?:\\s+va_[a-f0-9]{48})?\\s*$/i', $text) === 1;
    }

    /** @return array<string,mixed> */
    public static function redactedConsentUpdate(array $update, int $updateId): array
    {
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $text = trim((string)($message['text'] ?? ''));
        $command = str_starts_with(strtolower($text), '/stop') ? 'stop' : 'start';
        $redact = static fn(string $value, string $label): string => $value === '' ? '' : TelegramAssistantPrivacy::redactForLog($value, $label);
        return [
            'update_id'=>$updateId,
            'update_type'=>'message',
            'payload_redacted'=>true,
            'alert_command'=>$command,
            'message_id'=>(int)($message['message_id'] ?? 0),
            'chat'=>$redact((string)($message['chat']['id'] ?? ''), 'alert-chat'),
            'from'=>$redact((string)($message['from']['id'] ?? ''), 'alert-user'),
        ];
    }

    /**
     * Queue one minimal, encrypted alert per opted-in recipient.
     * It is deliberately a no-op until every delivery gate is explicitly on.
     */
    public static function enqueueOrderEvent(array $order, string $eventType, int $eventId = 0): int
    {
        if (!DeliveryPolicy::telegramAlertsEnabled()) return 0;
        $orderId = (int)($order['id'] ?? 0);
        $publicId = strtoupper(trim((string)($order['public_id'] ?? '')));
        if ($orderId < 1 || preg_match('/^[A-Z0-9]{8,40}$/', $publicId) !== 1) return 0;
        $eventType = self::eventType($eventType);
        $isManager = in_array($eventType, ['visa_request_created','visa_application_received','visa_document_uploaded'], true);
        $statement = Database::connection()->prepare(
            "SELECT s.* FROM telegram_alert_subscriptions s JOIN telegram_connections c ON c.id=s.connection_id "
            . "WHERE s.recipient_type=:recipient AND s.status='opted_in' AND c.is_enabled=1 AND c.webhook_status='active'"
            . ($isManager ? '' : " AND source_type='travel_order' AND source_id=:source_id")
            . ' ORDER BY id'
        );
        $params = ['recipient'=>$isManager ? 'manager' : 'customer'];
        if (!$isManager) $params['source_id'] = $orderId;
        $statement->execute($params);
        $count = 0;
        foreach ($statement->fetchAll() as $subscription) {
            $locale = self::locale((string)($subscription['locale'] ?? $order['locale'] ?? 'fa'));
            $text = self::eventText($locale, $isManager, $eventType, $publicId, (int)$order['id'], (string)($order['status'] ?? ''));
            $revision = $eventId > 0 ? (string)$eventId : hash('sha256', $eventType . '|' . $orderId . '|' . ($order['updated_at'] ?? '') . '|' . ($order['status'] ?? ''));
            $count += self::queueSubscriptionText($subscription, $eventType, $revision, $text, self::CUSTOMER_SOURCE, $orderId) ? 1 : 0;
        }
        return $count;
    }

    public static function setSubscriptionStatus(int $subscriptionId, string $status): void
    {
        if ($subscriptionId < 1 || !in_array($status, ['paused','revoked'], true)) {
            throw new RuntimeException('وضعیت اشتراک هشدار معتبر نیست.');
        }
        $statement = Database::connection()->prepare('SELECT id FROM telegram_alert_subscriptions WHERE id=:id');
        $statement->execute(['id'=>$subscriptionId]);
        if (!$statement->fetchColumn()) throw new RuntimeException('اشتراک هشدار پیدا نشد.');
        Database::connection()->prepare(
            'UPDATE telegram_alert_subscriptions SET status=:status,revoked_at=CASE WHEN :revoked=1 THEN CURRENT_TIMESTAMP ELSE revoked_at END,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
        )->execute(['status'=>$status,'revoked'=>$status === 'revoked' ? 1 : 0,'id'=>$subscriptionId]);
        if ($status === 'revoked') self::cancelSubscriptions([$subscriptionId]);
    }

    /** @return array<string,mixed>|null */
    private static function activeConnection(): ?array
    {
        $row = Database::connection()->query(
            "SELECT * FROM telegram_connections WHERE is_enabled=1 AND webhook_status='active' AND bot_username<>'' ORDER BY id LIMIT 1"
        )->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array{available:bool,status:string,url?:string} */
    private static function provisionOptIn(array $connection, string $recipientType, string $sourceType, int $sourceId, string $locale, array $topics): array
    {
        self::assertActiveConnection($connection);
        if (!in_array($recipientType, ['manager','customer'], true) || $sourceId < 1) {
            throw new RuntimeException('دامنهٔ اشتراک هشدار معتبر نیست.');
        }
        $pdo = Database::connection();
        $find = $pdo->prepare(
            'SELECT * FROM telegram_alert_subscriptions WHERE connection_id=:connection AND source_type=:source_type AND source_id=:source_id AND recipient_type=:recipient LIMIT 1'
        );
        $find->execute([
            'connection'=>(int)$connection['id'],'source_type'=>$sourceType,'source_id'=>$sourceId,'recipient'=>$recipientType,
        ]);
        $existing = $find->fetch();
        if (is_array($existing) && (string)$existing['status'] === 'opted_in') {
            return ['available'=>true,'status'=>'opted_in'];
        }

        $sessionKey = 'tg-alert:' . (int)$connection['id'] . ':' . $recipientType . ':' . $sourceType . ':' . $sourceId;
        $sessionToken = (string)($_SESSION['telegram_alert_optin'][$sessionKey] ?? '');
        if (
            is_array($existing)
            && (string)$existing['status'] === 'pending'
            && preg_match('/^va_[a-f0-9]{48}$/', $sessionToken) === 1
            && hash_equals((string)($existing['consent_token_hash'] ?? ''), hash('sha256', $sessionToken))
            && strtotime((string)($existing['consent_expires_at'] ?? '')) > time()
        ) {
            return ['available'=>true,'status'=>'pending','url'=>'https://t.me/' . rawurlencode((string)$connection['bot_username']) . '?start=' . $sessionToken];
        }

        $token = 'va_' . bin2hex(random_bytes(24));
        $record = [
            'connection'=>(int)$connection['id'],'recipient'=>$recipientType,'source_type'=>$sourceType,'source_id'=>$sourceId,
            'topics'=>json_encode($topics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),'locale'=>$locale,
            'token'=>hash('sha256', $token),'expires'=>gmdate('Y-m-d H:i:s', time() + self::PENDING_TTL_SECONDS),
        ];
        if (is_array($existing)) {
            $pdo->prepare(
                "UPDATE telegram_alert_subscriptions SET telegram_chat_id='',telegram_user_id='',topics_json=:topics,locale=:locale,status='pending',"
                . "consent_version='v1',consent_token_hash=:token,consent_expires_at=:expires,consented_at=NULL,revoked_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id"
            )->execute($record + ['id'=>(int)$existing['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO telegram_alert_subscriptions(connection_id,recipient_type,source_type,source_id,topics_json,locale,status,consent_version,consent_token_hash,consent_expires_at) "
                . "VALUES(:connection,:recipient,:source_type,:source_id,:topics,:locale,'pending','v1',:token,:expires)"
            )->execute($record);
        }
        $_SESSION['telegram_alert_optin'][$sessionKey] = $token;
        return ['available'=>true,'status'=>'pending','url'=>'https://t.me/' . rawurlencode((string)$connection['bot_username']) . '?start=' . $token];
    }

    private static function assertActiveConnection(array $connection): void
    {
        if ((int)($connection['id'] ?? 0) < 1 || (int)($connection['is_enabled'] ?? 0) !== 1
            || (string)($connection['webhook_status'] ?? '') !== 'active'
            || preg_match('/^[A-Za-z0-9_]{5,32}$/', (string)($connection['bot_username'] ?? '')) !== 1) {
            throw new RuntimeException('اتصال فعال و دارای نام کاربری ربات برای هشدار Telegram لازم است.');
        }
    }

    private static function queueSubscriptionText(array $subscription, string $eventType, string $revision, string $text, ?string $sourceType = null, ?int $sourceId = null): bool
    {
        $connectionId = (int)($subscription['connection_id'] ?? 0);
        $subscriptionId = (int)($subscription['id'] ?? 0);
        $chatId = (string)($subscription['telegram_chat_id'] ?? '');
        if ($connectionId < 1 || $subscriptionId < 1 || (string)($subscription['status'] ?? '') !== 'opted_in' || !self::validTelegramId($chatId)) {
            return false;
        }
        $eventType = self::eventType($eventType);
        $sourceType = $sourceType ?? (string)($subscription['source_type'] ?? 'system');
        $sourceId = $sourceId ?? (int)($subscription['source_id'] ?? 0);
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $sourceType) !== 1 || $sourceId < 0) return false;
        $dedupe = hash('sha256', implode('|', ['travel-alert',$connectionId,$subscriptionId,$sourceType,$sourceId,$eventType,$revision]));
        $payload = json_encode(['text'=>mb_substr(trim($text), 0, 3_700)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sealed = SecretStore::seal($payload, self::outboxContext($connectionId, $dedupe));
        try {
            $statement = Database::connection()->prepare(
                "INSERT INTO telegram_alert_outbox(connection_id,subscription_id,source_type,source_id,event_type,dedupe_key,payload_sealed,status,max_attempts,next_attempt_at,expires_at) "
                . "VALUES(:connection,:subscription,:source_type,:source_id,:event_type,:dedupe,:payload,'pending',5,CURRENT_TIMESTAMP,:expires) ON CONFLICT(dedupe_key) DO NOTHING"
            );
            $statement->execute([
                'connection'=>$connectionId,'subscription'=>$subscriptionId,'source_type'=>$sourceType,'source_id'=>$sourceId,
                'event_type'=>$eventType,'dedupe'=>$dedupe,'payload'=>$sealed,
                'expires'=>gmdate('Y-m-d H:i:s', time() + self::OUTBOX_TTL_SECONDS),
            ]);
            return $statement->rowCount() === 1;
        } catch (Throwable $error) {
            error_log('[VazinCMS] travel alert queue failure event=' . $eventType . ' class=' . $error::class);
            return false;
        }
    }

    /** @param list<int> $subscriptionIds */
    private static function cancelSubscriptions(array $subscriptionIds): void
    {
        foreach ($subscriptionIds as $subscriptionId) {
            if ($subscriptionId < 1) continue;
            Database::connection()->prepare(
                "UPDATE telegram_alert_outbox SET status='cancelled',locked_at=NULL,lock_token=NULL,updated_at=CURRENT_TIMESTAMP "
                . "WHERE subscription_id=:subscription AND status IN ('pending','processing')"
            )->execute(['subscription'=>$subscriptionId]);
        }
    }

    private static function validTelegramId(string $value): bool
    {
        return preg_match('/^-?[1-9][0-9]{0,19}$/', $value) === 1;
    }

    private static function locale(string $locale): string
    {
        return in_array($locale, ['fa','ru','en','ar'], true) ? $locale : 'fa';
    }

    private static function eventType(string $eventType): string
    {
        if (!in_array($eventType, [
            'visa_request_created','visa_application_received','visa_document_uploaded',
            'visa_status_changed','visa_document_reviewed','visa_customer_message','subscription_confirmed',
        ], true)) {
            throw new RuntimeException('نوع رویداد هشدار معتبر نیست.');
        }
        return $eventType;
    }

    private static function outboxContext(int $connectionId, string $dedupe): string
    {
        return 'telegram.alert.' . $connectionId . '.' . substr(hash('sha256', $dedupe), 0, 32);
    }

    private static function confirmationText(string $locale): string
    {
        return match (self::locale($locale)) {
            'ru'=>'Уведомления по заявке подключены. Чтобы отключить их, отправьте /stop.',
            'en'=>'Your case notifications are on. Send /stop at any time to turn them off.',
            default=>'هشدارهای پرونده فعال شد. برای لغو، هر زمان /stop را ارسال کنید.',
        };
    }

    private static function eventText(string $locale, bool $manager, string $eventType, string $publicId, int $orderId, string $status): string
    {
        $base = rtrim((string)getenv('APP_URL'), '/');
        $locale = self::locale($locale);
        if ($manager) {
            $url = $base !== '' ? $base . '/admin/visa-orders/' . $orderId : '';
            $body = match ($locale) {
                'ru'=>match ($eventType) {
                    'visa_request_created'=>"Новая визовая заявка {$publicId} ожидает проверки.",
                    'visa_application_received'=>"Полная анкета по делу {$publicId} получена и ожидает проверки.",
                    default=>"По делу {$publicId} загружен документ; требуется проверка.",
                },
                'en'=>match ($eventType) {
                    'visa_request_created'=>"New visa case {$publicId} is ready for review.",
                    'visa_application_received'=>"The full application for {$publicId} is ready for review.",
                    default=>"A document was uploaded for {$publicId}; review is needed.",
                },
                default=>match ($eventType) {
                    'visa_request_created'=>"درخواست ویزای {$publicId} برای بررسی ثبت شد.",
                    'visa_application_received'=>"فرم کامل پروندهٔ {$publicId} برای بررسی دریافت شد.",
                    default=>"یک مدرک برای پروندهٔ {$publicId} بارگذاری شد و نیاز به بررسی دارد.",
                },
            };
            return $url === '' ? $body : $body . "\n" . $url;
        }
        $url = $base !== '' ? $base . '/' . rawurlencode($locale) . '/order/' . rawurlencode($publicId) : '';
        $body = match ($locale) {
            'ru'=>'По вашему визовому делу ' . $publicId . ' есть обновление. Откройте защищённый кабинет для просмотра.',
            'en'=>'Your visa case ' . $publicId . ' has an update. Open the secure case portal to view it.',
            default=>'برای پروندهٔ ویزای ' . $publicId . ' یک به‌روزرسانی ثبت شد. برای مشاهده، وارد صفحهٔ امن پرونده شوید.',
        };
        return $url === '' ? $body : $body . "\n" . $url;
    }
}
