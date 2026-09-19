<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

final class TelegramAssistantPrivacy
{
    public const DEFAULT_RETENTION_DAYS = 30;
    public const MIN_RETENTION_DAYS = 1;
    public const MAX_RETENTION_DAYS = 90;
    private const MAX_CONTENT_BYTES = 131_072;

    /**
     * Prepare assistant text for storage using SecretStore's context-bound
     * AES-GCM envelope. The random content key is a locator/AAD component, not
     * an encryption key; APP_KEY remains the only encryption root.
     *
     * @return array{content_encrypted:string,content_key:string,expires_at:string}
     */
    public static function protectContent(int $connectionId, string $content, ?int $retentionDays = null): array
    {
        self::assertConnectionId($connectionId);
        $bytes = strlen($content);
        if ($bytes < 1 || $bytes > self::MAX_CONTENT_BYTES) {
            throw new RuntimeException('اندازهٔ متن دستیار Telegram معتبر نیست.');
        }

        $contentKey = bin2hex(random_bytes(16));
        return [
            'content_encrypted'=>SecretStore::seal($content, self::context($connectionId, $contentKey)),
            'content_key'=>$contentKey,
            'expires_at'=>self::expiresAt($retentionDays),
        ];
    }

    public static function revealContent(int $connectionId, string $contentEncrypted, string $contentKey): string
    {
        self::assertConnectionId($connectionId);
        return SecretStore::open($contentEncrypted, self::context($connectionId, $contentKey));
    }

    public static function clampRetentionDays(?int $requestedDays): int
    {
        if ($requestedDays === null) return self::DEFAULT_RETENTION_DAYS;
        return max(self::MIN_RETENTION_DAYS, min(self::MAX_RETENTION_DAYS, $requestedDays));
    }

    public static function expiresAt(?int $retentionDays = null, ?int $fromTimestamp = null): string
    {
        $base = $fromTimestamp ?? time();
        if ($base < 1) throw new RuntimeException('زمان پایهٔ نگه‌داری معتبر نیست.');
        return gmdate('Y-m-d H:i:s', $base + self::clampRetentionDays($retentionDays) * 86_400);
    }

    /**
     * Return a safe log token instead of attempting to recognize every form of
     * personal data. The digest permits correlation without exposing names,
     * chat text, phone numbers, tokens, initData, or Telegram identifiers.
     */
    public static function redactForLog(string $value, string $label = 'telegram-content'): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,39}$/', $label) !== 1) {
            throw new RuntimeException('برچسب ثبت رویداد معتبر نیست.');
        }
        $applicationKey = trim((string)getenv('APP_KEY'));
        if (strlen($applicationKey) < 32) {
            throw new RuntimeException('APP_KEY برای ثبت امن رویداد کافی نیست.');
        }
        $logKey = hash_hmac('sha256', 'telegram-assistant-log-v1', $applicationKey, true);
        return '[' . $label . ' redacted hmac=' . substr(hash_hmac('sha256', $value, $logKey), 0, 16)
            . ' bytes=' . strlen($value) . ']';
    }

    /**
     * Permanently remove expired assistant messages for exactly one connection.
     * No dynamic table/column identifiers are accepted, preventing accidental
     * cross-tenant or arbitrary-table deletion.
     */
    public static function purgeExpired(int $connectionId, int $limit = 1_000): int
    {
        self::assertConnectionId($connectionId);
        $limit = max(1, min(5_000, $limit));
        $statement = Database::connection()->prepare(
            'DELETE FROM telegram_assistant_messages WHERE id IN ('
            . 'SELECT id FROM telegram_assistant_messages '
            . 'WHERE connection_id=:connection_id AND expires_at<=CURRENT_TIMESTAMP '
            . 'ORDER BY id LIMIT :purge_limit)'
        );
        $statement->bindValue(':connection_id', $connectionId, PDO::PARAM_INT);
        $statement->bindValue(':purge_limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->rowCount();
    }

    private static function context(int $connectionId, string $contentKey): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $contentKey) !== 1) {
            throw new RuntimeException('کلید محتوای دستیار Telegram معتبر نیست.');
        }
        return 'telegram.assistant.c' . $connectionId . '.' . $contentKey;
    }

    private static function assertConnectionId(int $connectionId): void
    {
        if ($connectionId < 1) throw new RuntimeException('اتصال دستیار Telegram معتبر نیست.');
    }
}
