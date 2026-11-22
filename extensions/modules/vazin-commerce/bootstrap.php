<?php
declare(strict_types=1);

require_once __DIR__ . '/CommerceService.php';

use VazinCMS\{Access, ModuleContext, Security, View};
use VazinCMS\Modules\Commerce\CommerceService;

return static function (ModuleContext $module): void {
    $service = static fn(): CommerceService => new CommerceService($module->db());
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $module->registerBlock('product-grid', [
        'label' => 'شبکه محصولات فروشگاه',
        'category' => 'commerce',
        'schema' => [
            'limit' => 'int',
            'heading' => 'string',
        ],
    ], static function (array $data) use ($service, $esc): string {
        $limit = max(1, min(24, (int) ($data['limit'] ?? 6)));
        $heading = trim((string) ($data['heading'] ?? 'محصولات منتخب'));
        $products = $service()->listProducts(true, $limit);
        $html = '<section class="vz-commerce-grid">';
        if ($heading !== '') {
            $html .= '<h2>' . $esc($heading) . '</h2>';
        }
        if ($products === []) {
            $html .= '<p class="vz-commerce-empty">هنوز محصولی ثبت نشده است.</p></section>';
            return $html;
        }
        $html .= '<div class="vz-commerce-cards">';
        foreach ($products as $p) {
            $inStock = !empty($p['is_digital']) || (int) $p['stock'] > 0;
            $badge = $inStock ? 'موجود' : 'ناموجود';
            $html .= '<article class="vz-product-card"><h3>' . $esc((string) $p['title']) . '</h3>'
                . '<p>' . $esc((string) $p['summary']) . '</p>'
                . '<div class="vz-product-meta"><strong>' . number_format((float) $p['price']) . ' ' . $esc((string) $p['currency']) . '</strong>'
                . '<span>' . $badge . '</span></div>'
                . '<a href="/shop?product=' . (int) $p['id'] . '">خرید آنلاین</a></article>';
        }
        return $html . '</div></section>';
    });

    $module->api('GET', 'products', static fn(array $input, array $query): array => [
        'items' => $service()->listProducts(true, max(1, min(100, (int) ($query['limit'] ?? 25)))),
    ]);

    $module->api('POST', 'checkout', static function (array $input) use ($service): array {
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $customer = is_array($input['customer'] ?? null) ? $input['customer'] : $input;
        return $service()->checkout($items, $customer);
    });

    $module->get('/shop', static function () use ($service, $esc): void {
        $products = $service()->listProducts(true, 50);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>فروشگاه آنلاین</title></head><body><main class="vz-shop-container"><h1>فروشگاه آنلاین</h1>';
        foreach ($products as $p) {
            echo '<article><h2>' . $esc((string) $p['title']) . '</h2><p>' . $esc((string) $p['summary']) . '</p><strong>' . number_format((float) $p['price']) . ' ' . $esc((string) $p['currency']) . '</strong></article>';
        }
        echo '</main></body></html>';
    });

    $module->any('/admin/commerce', static function () use ($service, $esc): void {
        Access::require('sales');
        $svc = $service();
        $message = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $action = (string) ($_POST['action'] ?? 'create_product');
                if ($action === 'create_product') {
                    $svc->createProduct($_POST);
                    $message = 'محصول جدید با موفقیت ثبت شد.';
                } elseif ($action === 'create_coupon') {
                    $svc->createCoupon($_POST);
                    $message = 'کد تخفیف ثبت شد.';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $products = $svc->listProducts(false, 50);
        $orders = $svc->listOrders(30);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مدیریت فروشگاه</title></head><body><main><h1>مدیریت فروشگاه و سفارش‌ها</h1>';
        if ($message !== '') echo '<p class="notice-ok">' . $esc($message) . '</p>';
        if ($error !== '') echo '<p class="notice-err">' . $esc($error) . '</p>';
        echo '<h2>محصولات (' . count($products) . ')</h2><h2>سفارش‌ها (' . count($orders) . ')</h2></main></body></html>';
    });
};
