<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use RuntimeException;

final class CapabilityGuard
{
    public const ALL_CAPABILITIES = [
        'routes:public'    => 'ثبت مسیرهای عمومی وب',
        'routes:admin'     => 'ثبت صفحات پنل مدیریت',
        'routes:api'       => 'ثبت نقاط پایانی REST API ماژول',
        'hooks:listen'     => 'دریافت رویدادهای سیستم (Actions)',
        'hooks:filter'     => 'تغییر خروجی‌ها از طریق فیلترهای تایپ‌شده (Filters)',
        'blocks:register'  => 'ثبت بلوک‌های بصری در صفحه‌ساز و قالب‌ساز',
        'content:read'     => 'خواندن محتوا و ساختار سایت جاری',
        'content:write'    => 'ایجاد و ویرایش محتوای سایت جاری',
        'content:types'    => 'تعریف انواع محتوای سفارشی، فیلدها و دسته‌بندی‌ها',
        'db:isolated'      => 'ایجاد و مدیریت جداول دیتابیس ایزوله اختصاصی ماژول',
        'settings:manage'  => 'خواندن و ذخیره تنظیمات اختصاصی ماژول',
        'payments:gateway' => 'ثبت یا استفاده از درگاه‌های پرداخت استاندارد',
        'auth:provider'    => 'ثبت ارائه‌دهنده احراز هویت یکپارچه (SSO)',
        'media:read'       => 'دسترسی خواندنی به کتابخانه رسانه سایت',
        'http:outbound'    => 'ارسال درخواست HTTPS خروجی به دامنه‌های مجاز اعلام‌شده',
    ];

    public const SCHEMA_V1_DEFAULT_CAPABILITIES = [
        'routes:public',
        'routes:admin',
        'hooks:listen',
    ];

    public const TENANT_SAFE_CAPABILITIES = [
        'routes:public',
        'routes:admin',
        'routes:api',
        'hooks:listen',
        'hooks:filter',
        'blocks:register',
        'content:read',
        'content:write',
        'content:types',
        'db:isolated',
        'settings:manage',
        'payments:gateway',
        'media:read',
        'http:outbound',
    ];

    /**
     * @param list<mixed> $permissions
     * @return list<string>
     */
    public static function validateList(array $permissions): array
    {
        $normalized = [];
        foreach ($permissions as $perm) {
            if (!is_string($perm)) {
                throw new RuntimeException('مجوز ماژول باید رشته معتبر باشد.');
            }
            $perm = strtolower(trim($perm));
            if (!isset(self::ALL_CAPABILITIES[$perm])) {
                throw new RuntimeException('مجوز ناشناخته در manifest ماژول: ' . $perm);
            }
            $normalized[$perm] = true;
        }
        return array_keys($normalized);
    }

    /**
     * @return list<string>
     */
    public static function capabilitiesFor(ExtensionManifest $manifest): array
    {
        $data = $manifest->data();
        if (($data['schema'] ?? 1) === 1) {
            return self::SCHEMA_V1_DEFAULT_CAPABILITIES;
        }
        return is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
    }

    public static function has(ExtensionManifest $manifest, string $capability): bool
    {
        return in_array($capability, self::capabilitiesFor($manifest), true);
    }

    public static function assert(ExtensionManifest $manifest, string $capability): void
    {
        if (!self::has($manifest, $capability)) {
            throw new RuntimeException(sprintf(
                'ماژول «%s» مجوز «%s» را در vazin-extension.json اعلام نکرده است.',
                $manifest->key(),
                $capability
            ));
        }
    }

    public static function isTenantSafe(ExtensionManifest $manifest): bool
    {
        $data = $manifest->data();
        if (($data['schema'] ?? 1) < 2 || empty($data['site_scoped'])) {
            return false;
        }
        foreach (self::capabilitiesFor($manifest) as $cap) {
            if (!in_array($cap, self::TENANT_SAFE_CAPABILITIES, true)) {
                return false;
            }
        }
        return true;
    }

    public static function assertOutboundUrl(ExtensionManifest $manifest, string $url): string
    {
        self::assert($manifest, 'http:outbound');
        if (!DeliveryPolicy::externalEnabled()) {
            throw new RuntimeException('ارسال خروجی خارجی در تنظیمات امنیتی سرور غیرفعال است (EXTERNAL_DELIVERY_ENABLED=false).');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException('درخواست خروجی ماژول فقط با پروتکل HTTPS مجاز است.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('ارسال اطلاعات کاربری در URL خروجی مجاز نیست.');
        }
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $allowedHosts = $manifest->data()['outbound_hosts'] ?? [];
        if ($host === '' || !is_array($allowedHosts) || !in_array($host, $allowedHosts, true)) {
            throw new RuntimeException(sprintf(
                'دامنه مقصد «%s» در فهرست outbound_hosts ماژول «%s» مجاز نشده است.',
                $host,
                $manifest->key()
            ));
        }
        self::assertPublicHostIps($host);
        return $url;
    }

    private static function assertPublicHostIps(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('اتصال به IP خصوصی یا رزروشده مجاز نیست (SSRF Protection).');
            }
            return;
        }
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records) || $records === []) {
            return;
        }
        foreach ($records as $rec) {
            $ip = (string) ($rec['ip'] ?? $rec['ipv6'] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('دامنه مقصد به IP داخلی اشاره می‌کند و مسدود شد (SSRF Protection).');
            }
        }
    }
}
