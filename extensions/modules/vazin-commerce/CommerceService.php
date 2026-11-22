<?php
declare(strict_types=1);

namespace VazinCMS\Modules\Commerce;

use InvalidArgumentException;
use RuntimeException;
use VazinCMS\{ExtensionRuntime, ModuleDatabase, PageCacheService, PaymentGatewayRegistry};

final class CommerceService
{
    private bool $ready = false;

    public function __construct(private ModuleDatabase $db)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->db->ensureTable('products', [
            'sku'           => 'string:80',
            'slug'          => 'string:180',
            'title'         => 'string:255',
            'summary'       => 'text',
            'price'         => 'decimal',
            'compare_price' => 'decimal:nullable',
            'currency'      => 'string:10:default=IRR',
            'stock'         => 'int:default=0',
            'is_digital'    => 'bool',
            'image_url'     => 'string:500',
            'status'        => 'string:20:default=active',
        ], [['sku'], ['slug']]);

        $this->db->ensureTable('coupons', [
            'code'           => 'string:60',
            'discount_type'  => 'string:20:default=percent',
            'discount_value' => 'decimal',
            'min_order'      => 'decimal',
            'max_uses'       => 'int:default=0',
            'used_count'     => 'int:default=0',
            'status'         => 'string:20:default=active',
        ], [['code']]);

        $this->db->ensureTable('orders', [
            'order_number'   => 'string:60',
            'customer_name'  => 'string:160',
            'customer_email' => 'string:180',
            'customer_phone' => 'string:40',
            'address'        => 'text',
            'items_json'     => 'json',
            'subtotal'       => 'decimal',
            'discount'       => 'decimal',
            'total'          => 'decimal',
            'currency'       => 'string:10:default=IRR',
            'coupon_code'    => 'string:60',
            'gateway_key'    => 'string:60',
            'provider_ref'   => 'string:160',
            'payment_status' => 'string:30:default=pending',
            'order_status'   => 'string:30:default=new',
        ], [['order_number']]);

        $this->ready = true;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createProduct(array $input): array
    {
        $this->ensureSchema();
        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) < 2) {
            throw new InvalidArgumentException('نام محصول باید حداقل ۲ کاراکتر باشد.');
        }
        $sku = strtoupper(trim((string) ($input['sku'] ?? ('SKU-' . strtoupper(bin2hex(random_bytes(3)))))));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,78}$/', $sku) !== 1) {
            throw new InvalidArgumentException('کد محصول (SKU) معتبر نیست.');
        }
        $slug = self::slugify((string) ($input['slug'] ?? $title));
        $price = (float) ($input['price'] ?? 0);
        if ($price < 0) {
            throw new InvalidArgumentException('قیمت محصول نمی‌تواند منفی باشد.');
        }
        $stock = max(0, (int) ($input['stock'] ?? 0));
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'IRR')));
        if (preg_match('/^[A-Z]{3,6}$/', $currency) !== 1) {
            $currency = 'IRR';
        }

        $statusVal = (string) ($input['status'] ?? 'active');
        $id = $this->db->insert('products', [
            'sku' => $sku,
            'slug' => $slug,
            'title' => $title,
            'summary' => mb_substr(trim((string) ($input['summary'] ?? '')), 0, 4000),
            'price' => $price,
            'compare_price' => isset($input['compare_price']) && $input['compare_price'] !== '' ? (float) $input['compare_price'] : null,
            'currency' => $currency,
            'stock' => $stock,
            'is_digital' => !empty($input['is_digital']),
            'image_url' => mb_substr(trim((string) ($input['image_url'] ?? '')), 0, 500),
            'status' => in_array($statusVal, ['active', 'draft', 'archived'], true) ? $statusVal : 'active',
        ]);
        PageCacheService::invalidateTag('module:vazin-commerce');
        return $this->db->find('products', $id) ?? ['id' => $id];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProducts(bool $activeOnly = true, int $limit = 50): array
    {
        $this->ensureSchema();
        return $this->db->where('products', $activeOnly ? ['status' => 'active'] : [], 'id DESC', $limit);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function createCoupon(array $input): int
    {
        $this->ensureSchema();
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if (preg_match('/^[A-Z0-9_-]{3,40}$/', $code) !== 1) {
            throw new InvalidArgumentException('کد تخفیف معتبر نیست.');
        }
        $type = in_array(($input['discount_type'] ?? 'percent'), ['percent', 'fixed'], true) ? (string) $input['discount_type'] : 'percent';
        $val = (float) ($input['discount_value'] ?? 0);
        if ($val <= 0 || ($type === 'percent' && $val > 100)) {
            throw new InvalidArgumentException('مقدار تخفیف معتبر نیست.');
        }
        return $this->db->insert('coupons', [
            'code' => $code,
            'discount_type' => $type,
            'discount_value' => $val,
            'min_order' => max(0.0, (float) ($input['min_order'] ?? 0)),
            'max_uses' => max(0, (int) ($input['max_uses'] ?? 0)),
            'used_count' => 0,
            'status' => 'active',
        ]);
    }

    /**
     * @param list<array{product_id:int,qty:int}> $items
     * @param array{name:string,email:string,phone?:string,address?:string,coupon_code?:string,gateway?:string} $customer
     * @return array<string,mixed>
     */
    public function checkout(array $items, array $customer): array
    {
        $this->ensureSchema();
        if ($items === []) {
            throw new InvalidArgumentException('سبد خرید خالی است.');
        }
        $name = trim((string) ($customer['name'] ?? ''));
        $email = strtolower(trim((string) ($customer['email'] ?? '')));
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('نام و ایمیل معتبر خریدار الزامی است.');
        }

        $lineItems = [];
        $subtotal = 0.0;
        $currency = 'IRR';

        foreach ($items as $req) {
            $pid = (int) ($req['product_id'] ?? 0);
            $qty = max(1, min(100, (int) ($req['qty'] ?? 1)));
            $product = $this->db->find('products', $pid);
            if ($product === null || ($product['status'] ?? '') !== 'active') {
                throw new InvalidArgumentException('محصول انتخاب‌شده موجود یا فعال نیست.');
            }
            if (empty($product['is_digital']) && (int) $product['stock'] < $qty) {
                throw new RuntimeException('موجودی محصول «' . $product['title'] . '» کافی نیست.');
            }
            $currency = (string) ($product['currency'] ?: $currency);
            $unitPrice = (float) $product['price'];
            $lineTotal = round($unitPrice * $qty, 2);
            $subtotal += $lineTotal;
            $lineItems[] = [
                'product_id' => $pid,
                'sku' => $product['sku'],
                'title' => $product['title'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'is_digital' => (bool) $product['is_digital'],
            ];
        }

        $discount = 0.0;
        $couponCode = strtoupper(trim((string) ($customer['coupon_code'] ?? '')));
        $matchedCoupon = null;
        if ($couponCode !== '') {
            $matchedCoupon = $this->db->findOneBy('coupons', ['code' => $couponCode, 'status' => 'active']);
            if ($matchedCoupon === null) {
                throw new InvalidArgumentException('کد تخفیف معتبر نیست.');
            }
            if ((int) $matchedCoupon['max_uses'] > 0 && (int) $matchedCoupon['used_count'] >= (int) $matchedCoupon['max_uses']) {
                throw new InvalidArgumentException('ظرفیت استفاده از کد تخفیف تکمیل شده است.');
            }
            if ($subtotal < (float) $matchedCoupon['min_order']) {
                throw new InvalidArgumentException('مبلغ سفارش کمتر از حداقل مجاز کد تخفیف است.');
            }
            $discount = $matchedCoupon['discount_type'] === 'percent'
                ? round($subtotal * ((float) $matchedCoupon['discount_value'] / 100.0), 2)
                : min($subtotal, (float) $matchedCoupon['discount_value']);
        }

        $total = max(0.0, round($subtotal - $discount, 2));
        $gatewayKey = strtolower(trim((string) ($customer['gateway'] ?? 'offline-invoice')));
        $gateway = PaymentGatewayRegistry::requireGateway($gatewayKey);
        if (!$gateway->supportsCurrency($currency)) {
            throw new InvalidArgumentException('درگاه انتخاب‌شده از واحد پول ' . $currency . ' پشتیبانی نمی‌کند.');
        }

        $orderNumber = 'VZ-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $payment = $gateway->createCheckout([
            'order_number' => $orderNumber,
            'total' => $total,
            'currency' => $currency,
            'customer_email' => $email,
        ]);

        // Deduct physical stock & increment coupon usage
        foreach ($lineItems as $li) {
            if (!$li['is_digital']) {
                $curr = $this->db->find('products', (int) $li['product_id']);
                if ($curr !== null) {
                    $this->db->update('products', (int) $li['product_id'], [
                        'stock' => max(0, (int) $curr['stock'] - (int) $li['qty']),
                    ]);
                }
            }
        }
        if ($matchedCoupon !== null) {
            $this->db->update('coupons', (int) $matchedCoupon['id'], [
                'used_count' => (int) $matchedCoupon['used_count'] + 1,
            ]);
        }

        $orderId = $this->db->insert('orders', [
            'order_number' => $orderNumber,
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => mb_substr(trim((string) ($customer['phone'] ?? '')), 0, 40),
            'address' => mb_substr(trim((string) ($customer['address'] ?? '')), 0, 1000),
            'items_json' => $lineItems,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'currency' => $currency,
            'coupon_code' => $couponCode,
            'gateway_key' => $gateway->key(),
            'provider_ref' => (string) ($payment['provider_ref'] ?? ''),
            'payment_status' => (string) ($payment['status'] ?? 'pending'),
            'order_status' => $total <= 0.0 ? 'paid' : 'awaiting_payment',
        ]);

        PageCacheService::invalidateTag('module:vazin-commerce');
        ExtensionRuntime::emit('commerce.order_created', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total' => $total,
            'currency' => $currency,
        ]);

        return [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'currency' => $currency,
            'payment' => $payment,
        ];
    }

    /**
     * @return ?array<string,mixed>
     */
    public function findOrderByNumber(string $orderNumber): ?array
    {
        $this->ensureSchema();
        return $this->db->findOneBy('orders', ['order_number' => strtoupper(trim($orderNumber))]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrders(int $limit = 50): array
    {
        $this->ensureSchema();
        return $this->db->where('orders', [], 'id DESC', $limit);
    }

    private static function slugify(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = (string) preg_replace('/[^a-z0-9\x{0600}-\x{06FF}\x{0400}-\x{04FF}-]+/u', '-', $s);
        $s = trim($s, '-');
        return $s !== '' ? mb_substr($s, 0, 175) : ('prod-' . bin2hex(random_bytes(3)));
    }
}
