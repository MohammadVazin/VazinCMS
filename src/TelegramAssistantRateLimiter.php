<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

final class TelegramAssistantRateLimiter
{
    private const MAX_LIMIT = 10_000;
    private const MAX_WINDOW_SECONDS = 86_400;

    /**
     * Consume one request from an atomic, fixed-window bucket.
     *
     * Tenant and subject identifiers are hashed before storage so the limiter
     * table does not become another source of customer identifiers or IPs.
     * A rejected request follows the conflict WHERE=false path and therefore
     * does not update hit_count, timestamps, or any other persisted value.
     *
     * @return array{allowed:bool,retry_after:int,remaining:int,limit:int,window_seconds:int}
     */
    public static function consume(
        string $namespace,
        string $tenantKey,
        string $subjectKey,
        int $limit,
        int $windowSeconds
    ): array {
        self::validatePolicy($namespace, $tenantKey, $subjectKey, $limit, $windowSeconds);

        return self::consumeOnce(
            self::rateKey($namespace, $tenantKey, $subjectKey),
            $limit,
            $windowSeconds,
            true
        );
    }

    private static function consumeOnce(string $rateKey, int $limit, int $windowSeconds, bool $retryMissing): array
    {
        $pdo = Database::connection();
        $now = time();
        $startedAt = gmdate('Y-m-d H:i:s', $now);
        $expiresAt = gmdate('Y-m-d H:i:s', $now + $windowSeconds);

        $statement = $pdo->prepare(
            'INSERT INTO telegram_assistant_rate_limits(rate_key,hit_count,window_started_at,expires_at,updated_at) '
            . 'VALUES(:rate_key,1,:insert_started,:insert_expires,:insert_updated) '
            . 'ON CONFLICT(rate_key) DO UPDATE SET '
            . 'hit_count=CASE WHEN telegram_assistant_rate_limits.expires_at<=:reset_count_at THEN 1 ELSE telegram_assistant_rate_limits.hit_count+1 END,'
            . 'window_started_at=CASE WHEN telegram_assistant_rate_limits.expires_at<=:reset_started_at THEN :new_started ELSE telegram_assistant_rate_limits.window_started_at END,'
            . 'expires_at=CASE WHEN telegram_assistant_rate_limits.expires_at<=:reset_expires_at THEN :new_expires ELSE telegram_assistant_rate_limits.expires_at END,'
            . 'updated_at=:update_time '
            . 'WHERE telegram_assistant_rate_limits.expires_at<=:allow_expired_at '
            . 'OR telegram_assistant_rate_limits.hit_count<:allow_limit '
            . 'RETURNING hit_count,expires_at'
        );
        $statement->execute([
            'rate_key'=>$rateKey,
            'insert_started'=>$startedAt,
            'insert_expires'=>$expiresAt,
            'insert_updated'=>$startedAt,
            'reset_count_at'=>$startedAt,
            'reset_started_at'=>$startedAt,
            'new_started'=>$startedAt,
            'reset_expires_at'=>$startedAt,
            'new_expires'=>$expiresAt,
            'update_time'=>$startedAt,
            'allow_expired_at'=>$startedAt,
            'allow_limit'=>$limit,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $hits = (int)$row['hit_count'];
            return [
                'allowed'=>true,
                'retry_after'=>0,
                'remaining'=>max(0, $limit - $hits),
                'limit'=>$limit,
                'window_seconds'=>$windowSeconds,
            ];
        }

        // The rejected UPSERT made no change. This SELECT is intentionally the
        // only follow-up operation on the rejection path.
        $lookup = $pdo->prepare('SELECT expires_at FROM telegram_assistant_rate_limits WHERE rate_key=:rate_key');
        $lookup->execute(['rate_key'=>$rateKey]);
        $persistedExpiry = $lookup->fetchColumn();
        if (!is_string($persistedExpiry) || $persistedExpiry === '') {
            // A retention worker may have removed the expired bucket between
            // the UPSERT and SELECT. Retry once against the now-empty bucket.
            if ($retryMissing) return self::consumeOnce($rateKey, $limit, $windowSeconds, false);
            throw new RuntimeException('وضعیت محدودکنندهٔ دستیار Telegram قابل بازیابی نیست.');
        }
        // Migration columns are UTC TIMESTAMP/TEXT values. Parse explicitly as
        // UTC so the PHP host timezone cannot inflate or shorten retry_after.
        $expiryTimestamp = strtotime(substr($persistedExpiry, 0, 19) . ' UTC');

        return [
            'allowed'=>false,
            'retry_after'=>max(1, ($expiryTimestamp === false ? $now + $windowSeconds : $expiryTimestamp) - $now),
            'remaining'=>0,
            'limit'=>$limit,
            'window_seconds'=>$windowSeconds,
        ];
    }

    private static function validatePolicy(
        string $namespace,
        string $tenantKey,
        string $subjectKey,
        int $limit,
        int $windowSeconds
    ): void {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $namespace) !== 1) {
            throw new RuntimeException('فضای نام محدودکنندهٔ دستیار Telegram معتبر نیست.');
        }
        foreach (['tenant'=>$tenantKey, 'subject'=>$subjectKey] as $name=>$value) {
            $length = strlen($value);
            if ($length < 1 || $length > 512) {
                throw new RuntimeException('کلید ' . $name . ' محدودکنندهٔ دستیار Telegram معتبر نیست.');
            }
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new RuntimeException('سقف درخواست دستیار Telegram معتبر نیست.');
        }
        if ($windowSeconds < 1 || $windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new RuntimeException('پنجرهٔ زمانی دستیار Telegram معتبر نیست.');
        }
    }

    private static function rateKey(string $namespace, string $tenantKey, string $subjectKey): string
    {
        return hash('sha256', "telegram-assistant-rate-v1\0" . $namespace . "\0" . $tenantKey . "\0" . $subjectKey);
    }
}
