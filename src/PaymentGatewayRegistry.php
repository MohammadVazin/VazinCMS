<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use RuntimeException;
use VazinCMS\Contracts\PaymentGatewayInterface;

final class PaymentGatewayRegistry
{
    /** @var array<string,array{module:string,gateway:PaymentGatewayInterface}> */
    private static array $gateways = [];

    public static function register(string $moduleKey, PaymentGatewayInterface $gateway): void
    {
        $key = strtolower(trim($gateway->key()));
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $key) !== 1) {
            throw new InvalidArgumentException('Invalid payment gateway key.');
        }
        self::$gateways[$key] = ['module' => $moduleKey, 'gateway' => $gateway];
    }

    public static function get(string $key): ?PaymentGatewayInterface
    {
        self::ensureDefaults();
        return self::$gateways[$key]['gateway'] ?? null;
    }

    /** @return array<string,PaymentGatewayInterface> */
    public static function all(): array
    {
        self::ensureDefaults();
        $out = [];
        foreach (self::$gateways as $key => $entry) {
            $out[$key] = $entry['gateway'];
        }
        return $out;
    }

    public static function requireGateway(string $key): PaymentGatewayInterface
    {
        $gateway = self::get($key);
        if (!$gateway instanceof PaymentGatewayInterface) {
            throw new RuntimeException('درگاه پرداخت انتخاب‌شده فعال نیست: ' . $key);
        }
        return $gateway;
    }

    public static function reset(): void
    {
        self::$gateways = [];
    }

    private static function ensureDefaults(): void
    {
        if (isset(self::$gateways['offline-invoice'])) {
            return;
        }
        self::$gateways['offline-invoice'] = [
            'module' => 'core',
            'gateway' => new class implements PaymentGatewayInterface {
                public function key(): string { return 'offline-invoice'; }
                public function label(): string { return 'پرداخت آفلاین / کارت‌به‌کارت / پیش‌فاکتور'; }
                public function supportsCurrency(string $currency): bool { return true; }
                public function createCheckout(array $order, array $options = []): array
                {
                    $orderNo = (string) ($order['order_number'] ?? ('ORD-' . bin2hex(random_bytes(4))));
                    return [
                        'status' => 'pending_confirmation',
                        'provider_ref' => 'OFFLINE-' . $orderNo,
                        'checkout_url' => null,
                        'instructions' => (string) ($options['instructions'] ?? 'سفارش شما ثبت شد و در انتظار تأیید پرداخت است.'),
                    ];
                }
                public function verifyCallback(array $payload, array $headers = []): array
                {
                    return [
                        'verified' => false,
                        'order_number' => isset($payload['order_number']) ? (string) $payload['order_number'] : null,
                        'provider_ref' => null,
                        'status' => 'manual_review_required',
                    ];
                }
            },
        ];
    }
}
