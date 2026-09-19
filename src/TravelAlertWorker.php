<?php
declare(strict_types=1);

namespace VazinCMS;

use Throwable;

/** Processes consented, encrypted Travel/Visa Telegram alerts. */
final class TravelAlertWorker
{
    /** @return array{status:string,processed:int,sent:int,failed:int,cancelled:int} */
    public static function process(int $limit = 10): array
    {
        if (!DeliveryPolicy::telegramAlertsEnabled()) {
            return ['status'=>'disabled','processed'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0];
        }
        $pdo = Database::connection();
        $limit = max(1, min(50, $limit));
        $rows = $pdo->query(
            "SELECT id FROM telegram_alert_outbox WHERE status='pending' AND next_attempt_at<=CURRENT_TIMESTAMP "
            . "AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP) ORDER BY id LIMIT " . $limit
        )->fetchAll();
        $result = ['status'=>'ok','processed'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0];
        foreach ($rows as $candidate) {
            $lockToken = bin2hex(random_bytes(16));
            $claim = $pdo->prepare(
                "UPDATE telegram_alert_outbox SET status='processing',locked_at=CURRENT_TIMESTAMP,lock_token=:token,attempts=attempts+1,updated_at=CURRENT_TIMESTAMP "
                . "WHERE id=:id AND status='pending'"
            );
            $claim->execute(['token'=>$lockToken,'id'=>(int)$candidate['id']]);
            if ($claim->rowCount() !== 1) continue;
            $result['processed']++;
            $row = self::lockedRow((int)$candidate['id'], $lockToken);
            if ($row === null) continue;
            if ((string)$row['subscription_status'] !== 'opted_in' || !self::validTelegramId((string)$row['telegram_chat_id'])) {
                self::cancel((int)$row['id'], $lockToken);
                $result['cancelled']++;
                continue;
            }
            try {
                if ((int)$row['connection_enabled'] !== 1 || (string)$row['webhook_status'] !== 'active') {
                    throw new \RuntimeException('connection_unavailable');
                }
                $payload = json_decode(
                    SecretStore::open((string)$row['payload_sealed'], self::payloadContext((int)$row['connection_id'], (string)$row['dedupe_key'])),
                    true,
                    16,
                    JSON_THROW_ON_ERROR
                );
                $text = is_array($payload) ? mb_substr(trim((string)($payload['text'] ?? '')), 0, 3_700) : '';
                if ($text === '') throw new \RuntimeException('payload_invalid');
                // Recheck immediately before the irreversible network call so a
                // recent /stop can cancel a claimed job without sending it.
                if (!self::subscriptionStillOptedIn((int)$row['subscription_id'], (string)$row['telegram_chat_id'])) {
                    self::cancel((int)$row['id'], $lockToken);
                    $result['cancelled']++;
                    continue;
                }
                $api = new TelegramBotApi(TelegramSyncService::botToken($row));
                $response = $api->call('sendMessage', [
                    'chat_id'=>(string)$row['telegram_chat_id'],
                    'text'=>$text,
                    'disable_web_page_preview'=>true,
                ]);
                $telegramMessageId = max(0, (int)($response['message_id'] ?? 0));
                $sent = $pdo->prepare(
                    "UPDATE telegram_alert_outbox SET status='sent',telegram_message_id=:message,locked_at=NULL,lock_token=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP "
                    . "WHERE id=:id AND status='processing' AND lock_token=:token"
                );
                $sent->execute(['message'=>$telegramMessageId ?: null,'id'=>(int)$row['id'],'token'=>$lockToken]);
                if ($sent->rowCount() === 1) $result['sent']++;
            } catch (Throwable $error) {
                self::fail($row, $lockToken, $error);
                $result['failed']++;
            }
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private static function lockedRow(int $id, string $lockToken): ?array
    {
        $query = Database::connection()->prepare(
            'SELECT o.*,s.status AS subscription_status,s.telegram_chat_id,c.is_enabled AS connection_enabled,c.webhook_status,c.encrypted_bot_token,c.webhook_key '
            . 'FROM telegram_alert_outbox o JOIN telegram_alert_subscriptions s ON s.id=o.subscription_id '
            . 'JOIN telegram_connections c ON c.id=o.connection_id WHERE o.id=:id AND o.status=\'processing\' AND o.lock_token=:token'
        );
        $query->execute(['id'=>$id,'token'=>$lockToken]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    private static function subscriptionStillOptedIn(int $subscriptionId, string $chatId): bool
    {
        $query = Database::connection()->prepare(
            "SELECT 1 FROM telegram_alert_subscriptions WHERE id=:id AND status='opted_in' AND telegram_chat_id=:chat"
        );
        $query->execute(['id'=>$subscriptionId,'chat'=>$chatId]);
        return (bool)$query->fetchColumn();
    }

    private static function cancel(int $id, string $lockToken): void
    {
        Database::connection()->prepare(
            "UPDATE telegram_alert_outbox SET status='cancelled',locked_at=NULL,lock_token=NULL,updated_at=CURRENT_TIMESTAMP "
            . "WHERE id=:id AND status='processing' AND lock_token=:token"
        )->execute(['id'=>$id,'token'=>$lockToken]);
    }

    /** @param array<string,mixed> $row */
    private static function fail(array $row, string $lockToken, Throwable $error): void
    {
        $blocked = $error instanceof TelegramBotApiException && in_array((int)$error->getCode(), [400,403], true);
        $attempt = (int)$row['attempts'];
        $maxAttempts = max(1, (int)$row['max_attempts']);
        $retryAfter = $error instanceof TelegramBotApiException ? $error->retryAfter() : 0;
        $terminal = $blocked || $attempt >= $maxAttempts;
        $delay = $retryAfter > 0 ? $retryAfter : min(3_600, 60 * (2 ** min(6, $attempt)));
        $errorCode = $error instanceof TelegramBotApiException
            ? 'telegram_api_' . max(0, (int)$error->getCode())
            : 'delivery_failed';
        if ($blocked) {
            Database::connection()->prepare(
                "UPDATE telegram_alert_subscriptions SET status='blocked',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='opted_in'"
            )->execute(['id'=>(int)$row['subscription_id']]);
        }
        Database::connection()->prepare(
            "UPDATE telegram_alert_outbox SET status=:status,next_attempt_at=:next,locked_at=NULL,lock_token=NULL,last_error=:error,updated_at=CURRENT_TIMESTAMP "
            . "WHERE id=:id AND status='processing' AND lock_token=:token"
        )->execute([
            'status'=>$terminal ? ($blocked ? 'cancelled' : 'failed') : 'pending',
            'next'=>gmdate('Y-m-d H:i:s', time() + $delay),'error'=>$errorCode,
            'id'=>(int)$row['id'],'token'=>$lockToken,
        ]);
    }

    private static function payloadContext(int $connectionId, string $dedupe): string
    {
        return 'telegram.alert.' . $connectionId . '.' . substr(hash('sha256', $dedupe), 0, 32);
    }

    private static function validTelegramId(string $value): bool
    {
        return preg_match('/^-?[1-9][0-9]{0,19}$/', $value) === 1;
    }
}
