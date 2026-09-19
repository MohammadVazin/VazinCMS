<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class DeliveryPolicy
{
    private const TRUE_VALUES = ['1', 'true', 'yes', 'on'];

    public static function externalEnabled(): bool
    {
        return self::flag('EXTERNAL_DELIVERY_ENABLED');
    }

    public static function telegramEnabled(): bool
    {
        return self::externalEnabled() && self::telegramNetworkGateEnabled();
    }

    /** Exposes the dedicated gate for read-only operational readiness views. */
    public static function telegramNetworkGateEnabled(): bool
    {
        return self::flag('TELEGRAM_NETWORK_ENABLED');
    }

    public static function telegramBusinessEnabled(): bool
    {
        return self::telegramEnabled() && self::flag('TELEGRAM_BUSINESS_ENABLED');
    }

    /**
     * Travel and visa alerts are a separate consented delivery channel.
     * Keeping this gate independent prevents a newly configured bot from
     * sending transactional notices before the tenant has completed review.
     */
    public static function telegramAlertsEnabled(): bool
    {
        return self::telegramEnabled() && self::telegramAlertsGateEnabled();
    }

    /** Exposes the dedicated alert gate without granting delivery permission. */
    public static function telegramAlertsGateEnabled(): bool
    {
        return self::flag('TELEGRAM_ALERT_DELIVERY_ENABLED');
    }

    public static function telegramAssistantDeliveryEnabled(): bool
    {
        return self::telegramBusinessEnabled() && self::flag('TELEGRAM_ASSISTANT_DELIVERY_ENABLED');
    }

    public static function telegramAssistantAutoreplyEnabled(): bool
    {
        return self::telegramAssistantDeliveryEnabled() && self::flag('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED');
    }

    public static function telegramAssistantPreviewEnabled(): bool
    {
        return self::flag('TELEGRAM_ASSISTANT_PREVIEW_ENABLED');
    }

    public static function assertExternalEnabled(): void
    {
        if (!self::externalEnabled()) {
            throw new RuntimeException('ارسال خارجی غیرفعال است؛ EXTERNAL_DELIVERY_ENABLED باید صریحاً true باشد.');
        }
    }

    public static function assertTelegramEnabled(): void
    {
        if (!self::telegramEnabled()) {
            throw new RuntimeException('شبکهٔ Telegram غیرفعال است؛ هر دو کلید EXTERNAL_DELIVERY_ENABLED و TELEGRAM_NETWORK_ENABLED باید صریحاً true باشند.');
        }
    }

    public static function assertTelegramBusinessEnabled(): void
    {
        if (!self::telegramBusinessEnabled()) {
            throw new RuntimeException('Telegram Business غیرفعال است؛ قفل TELEGRAM_BUSINESS_ENABLED و همهٔ قفل‌های والد باید صریحاً true باشند.');
        }
    }

    public static function assertTelegramAlertsEnabled(): void
    {
        if (!self::telegramAlertsEnabled()) {
            throw new RuntimeException('هشدارهای سفر و ویزا در Telegram غیرفعال‌اند؛ TELEGRAM_ALERT_DELIVERY_ENABLED و همهٔ قفل‌های والد باید صریحاً true باشند.');
        }
    }

    public static function assertTelegramAssistantDeliveryEnabled(): void
    {
        if (!self::telegramAssistantDeliveryEnabled()) {
            throw new RuntimeException('ارسال دستیار Telegram غیرفعال است؛ TELEGRAM_ASSISTANT_DELIVERY_ENABLED و همهٔ قفل‌های والد باید صریحاً true باشند.');
        }
    }

    public static function assertTelegramAssistantAutoreplyEnabled(): void
    {
        if (!self::telegramAssistantAutoreplyEnabled()) {
            throw new RuntimeException('پاسخ خودکار دستیار Telegram غیرفعال است؛ TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED و همهٔ قفل‌های والد باید صریحاً true باشند.');
        }
    }

    public static function assertProviderEnabled(string $provider): void
    {
        self::assertExternalEnabled();
        if (strtolower(trim($provider)) === 'telegram') self::assertTelegramEnabled();
    }

    private static function flag(string $name): bool
    {
        $value = getenv($name);
        return is_string($value) && in_array(strtolower(trim($value)), self::TRUE_VALUES, true);
    }
}
