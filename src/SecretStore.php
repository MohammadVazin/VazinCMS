<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class SecretStore
{
    public static function seal(string $plaintext, string $context): string
    {
        if ($plaintext === '') throw new RuntimeException('مقدار محرمانه خالی است.');
        $aad = self::aad($context);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('رمزگذاری مقدار محرمانه ناموفق بود.');
        }
        return 'v1.' . self::encode($iv . $tag . $ciphertext);
    }

    public static function open(string $sealed, string $context): string
    {
        if (!str_starts_with($sealed, 'v1.')) throw new RuntimeException('نسخهٔ مقدار رمزگذاری‌شده پشتیبانی نمی‌شود.');
        $raw = self::decode(substr($sealed, 3));
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('مقدار رمزگذاری‌شده معتبر نیست.');
        $plaintext = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            self::aad($context)
        );
        if ($plaintext === false) throw new RuntimeException('بازکردن مقدار محرمانه ناموفق بود.');
        return $plaintext;
    }

    private static function key(): string
    {
        $applicationKey = trim((string) getenv('APP_KEY'));
        if (strlen($applicationKey) < 32) {
            throw new RuntimeException('APP_KEY حداقل باید ۳۲ نویسهٔ تصادفی داشته باشد.');
        }
        return hash_hmac('sha256', 'vazincms-secret-store-v1', $applicationKey, true);
    }

    private static function aad(string $context): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,99}$/', $context) !== 1) {
            throw new RuntimeException('زمینهٔ رمزگذاری معتبر نیست.');
        }
        return 'vazincms|' . $context . '|v1';
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string|false
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return false;
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
