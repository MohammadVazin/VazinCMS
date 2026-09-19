<?php
declare(strict_types=1);
namespace VazinCMS;

final class Security
{
    private static ?string $cspNonce = null;

    public static function cspNonce(): string
    {
        if (self::$cspNonce === null) {
            self::$cspNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        }
        return self::$cspNonce;
    }

    public static function csrf(): string
    {
        if (!isset($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['_csrf'];
    }

    public static function verifyCsrf(): void
    {
        $given = (string)($_POST['_csrf'] ?? '');
        self::verifyCsrfToken($given);
    }

    public static function verifyCsrfToken(string $given): void
    {
        if ($given === '' || !hash_equals(self::csrf(), $given)) {
            http_response_code(419);
            throw new \RuntimeException('درخواست منقضی یا نامعتبر است.');
        }
    }

    public static function clientIp(): ?string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
