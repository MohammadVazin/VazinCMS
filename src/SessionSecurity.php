<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class SessionSecurity
{
    private const NAME = 'vazin_cms_session';
    private const SAME_SITE = 'Lax';

    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::assertConfiguration();
            return;
        }
        if (session_status() === PHP_SESSION_DISABLED) {
            throw new RuntimeException('نشست امن PHP در دسترس نیست.');
        }

        self::configure();
        if (!session_start()) {
            throw new RuntimeException('نشست امن آغاز نشد.');
        }
        self::assertConfiguration();
        self::assertIdentifier(session_id());
    }

    /**
     * Starts or rotates the browser session before an OAuth authorization
     * request. A destroyed/stale session is never resumed from the request's
     * old cookie; an active session is retained but receives a new identifier.
     */
    public static function renewForOAuth(): string
    {
        self::assertHeadersAvailable();
        $staleId = session_id();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (session_status() === PHP_SESSION_DISABLED) {
                throw new RuntimeException('نشست امن PHP در دسترس نیست.');
            }
            $_SESSION = [];
            unset($_COOKIE[self::NAME]);
            if (session_id('') === false) {
                throw new RuntimeException('شناسه نشست قدیمی پاک نشد.');
            }
            self::boot();
        } else {
            self::assertConfiguration();
        }

        $beforeRotation = session_id();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('شناسه نشست ورود نوسازی نشد.');
        }
        $freshId = session_id();
        self::assertIdentifier($freshId);
        if (($staleId !== '' && hash_equals($staleId, $freshId))
            || ($beforeRotation !== '' && hash_equals($beforeRotation, $freshId))) {
            throw new RuntimeException('نشست ورود با شناسه تازه آغاز نشد.');
        }
        self::assertConfiguration();
        return $freshId;
    }

    public static function renewForAuthentication(): string
    {
        self::assertHeadersAvailable();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('نشست فعال Vazin ID برای ورود وجود ندارد.');
        }
        self::assertConfiguration();
        $previous = session_id();
        self::assertIdentifier($previous);
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('شناسه نشست احرازشده نوسازی نشد.');
        }
        $fresh = session_id();
        self::assertIdentifier($fresh);
        if (hash_equals($previous, $fresh)) {
            throw new RuntimeException('نشست احرازشده شناسه تازه دریافت نکرد.');
        }
        self::assertConfiguration();
        return $fresh;
    }

    public static function persistAndSuspend(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !session_write_close()) {
            throw new RuntimeException('نشست ورود به‌صورت پایدار ذخیره نشد.');
        }
    }

    public static function destroy(): void
    {
        self::assertHeadersAvailable();
        $name = session_name() !== '' ? session_name() : self::NAME;
        $parameters = session_get_cookie_params();
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && !session_destroy()) {
            throw new RuntimeException('نشست مرورگر به‌طور کامل لغو نشد.');
        }

        unset($_COOKIE[$name]);
        if (ini_get('session.use_cookies')) {
            if (!setcookie($name, '', [
                'expires' => time() - 42000,
                'path' => (string)($parameters['path'] ?? '/'),
                'domain' => (string)($parameters['domain'] ?? ''),
                'secure' => (bool)($parameters['secure'] ?? self::secureCookie()),
                'httponly' => true,
                'samesite' => self::SAME_SITE,
            ])) {
                throw new RuntimeException('کوکی نشست مرورگر لغو نشد.');
            }
        }
        if (session_id('') === false) {
            throw new RuntimeException('شناسه نشست مرورگر پاک نشد.');
        }
    }

    private static function configure(): void
    {
        self::assertHeadersAvailable();
        if (session_name(self::NAME) === false) {
            throw new RuntimeException('نام نشست امن تنظیم نشد.');
        }
        foreach ([
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_httponly' => '1',
        ] as $key => $value) {
            if (ini_set($key, $value) === false) {
                throw new RuntimeException('پیکربندی نشست امن کامل نشد.');
            }
        }
        if (!session_set_cookie_params([
            'httponly' => true,
            'secure' => self::secureCookie(),
            'samesite' => self::SAME_SITE,
            'path' => '/',
        ])) {
            throw new RuntimeException('ویژگی‌های کوکی نشست امن تنظیم نشد.');
        }
    }

    private static function assertConfiguration(): void
    {
        $parameters = session_get_cookie_params();
        if (session_name() !== self::NAME
            || (string)ini_get('session.use_strict_mode') !== '1'
            || (string)ini_get('session.use_only_cookies') !== '1'
            || (string)ini_get('session.use_trans_sid') !== '0'
            || empty($parameters['httponly'])
            || (bool)($parameters['secure'] ?? false) !== self::secureCookie()
            || strcasecmp((string)($parameters['samesite'] ?? ''), self::SAME_SITE) !== 0
            || (string)($parameters['path'] ?? '') !== '/') {
            throw new RuntimeException('پیکربندی نشست مرورگر امن نیست.');
        }
    }

    private static function secureCookie(): bool
    {
        $value = strtolower(trim((string)(getenv('SESSION_SECURE') ?: 'true')));
        if (!in_array($value, ['true', 'false'], true)) {
            throw new RuntimeException('تنظیم SESSION_SECURE معتبر نیست.');
        }
        $secure = $value === 'true';
        $required = strtolower(trim((string)getenv('CMS_AUTH_MODE'))) === 'vazin_id_only';
        if ($required && PHP_SAPI !== 'cli' && !$secure) {
            throw new RuntimeException('ورود اجباری Vazin ID به کوکی Secure نیاز دارد.');
        }
        return $secure;
    }

    private static function assertHeadersAvailable(): void
    {
        if (headers_sent($file, $line)) {
            throw new RuntimeException('نشست امن پس از ارسال پاسخ قابل تغییر نیست: '.basename($file).':'.$line);
        }
    }

    private static function assertIdentifier(string $identifier): void
    {
        if ($identifier === '' || strlen($identifier) > 256
            || preg_match('/\A[A-Za-z0-9,-]+\z/D', $identifier) !== 1) {
            throw new RuntimeException('شناسه نشست امن معتبر نیست.');
        }
    }
}
