<?php
declare(strict_types=1);

require_once __DIR__ . '/CommerceService.php';

use VazinCMS\{Access, ModuleContext, Security, UiLocale, View};
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

    $module->any('/shop', static function () use ($service): void {
        $svc = $service();
        $orderReceipt = null;
        $checkoutError = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $pid = (int) ($_POST['product_id'] ?? 0);
                $qty = max(1, (int) ($_POST['qty'] ?? 1));
                $orderReceipt = $svc->checkout(
                    [['product_id' => $pid, 'qty' => $qty]],
                    [
                        'name' => (string) ($_POST['name'] ?? ''),
                        'email' => (string) ($_POST['email'] ?? ''),
                        'coupon_code' => (string) ($_POST['coupon_code'] ?? ''),
                    ]
                );
            } catch (\Throwable $e) {
                $checkoutError = $e->getMessage();
            }
        }
        $products = $svc->listProducts(true, 50);
        View::renderPublic('shop', [
            'locale' => UiLocale::detect(),
            'meta' => ['title' => 'فروشگاه آنلاین', 'description' => 'کاتالوگ محصولات و خرید آنلاین'],
            'menu' => [],
            'products' => $products,
            'orderReceipt' => $orderReceipt,
            'checkoutError' => $checkoutError,
        ]);
    });

    $module->any('/admin/commerce', static function () use ($service): void {
        $user = Access::require('sales');
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
                    $message = 'کد تخفیف با موفقیت ایجاد شد.';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        View::render('commerce-admin', [
            'title' => 'فروشگاه و سفارش‌ها',
            'user' => $user,
            'products' => $svc->listProducts(false, 50),
            'orders' => $svc->listOrders(30),
            'message' => $message,
            'error' => $error,
        ]);
    });
};
