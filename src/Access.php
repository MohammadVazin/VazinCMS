<?php
declare(strict_types=1);
namespace VazinCMS;

final class Access
{
    public static function definitions(): array
    {
        return [
            'customers' => 'مشتریان و پروفایل‌ها',
            'catalog' => 'محصولات و تخفیف‌ها',
            'sales' => 'سفارش‌ها و فاکتورها',
            'services' => 'سرویس‌ها و تمدیدها',
            'support' => 'تیکت‌ها',
            'finance' => 'کیف پول، پرداخت و مالیات',
            'notifications' => 'اعلان‌ها',
            'audit' => 'گزارش رویدادها',
            'system' => 'وضعیت سامانه',
            'content' => 'محتوا و سفر',
            'telegram' => 'Telegram، دستیار و رزرو',
        ];
    }

    public static function allowed(array $user, string $permission): bool
    {
        if (($user['role'] ?? '') === 'owner') return true;
        if (!in_array($permission, array_keys(self::definitions()), true)) return false;
        $stmt = Database::connection()->prepare('SELECT is_granted FROM staff_permissions WHERE user_id=:id AND permission=:permission');
        $stmt->execute(['id' => $user['id'], 'permission' => $permission]);
        $value = $stmt->fetchColumn();
        if ($value !== false) return (bool)$value;
        $defaults = [
            'admin' => ['customers','catalog','sales','services','support','finance','notifications','audit','system','content','telegram'],
            'support' => ['customers','services','support','notifications'],
        ];
        return in_array($permission, $defaults[$user['role'] ?? ''] ?? [], true);
    }

    public static function require(string $permission): array
    {
        $user = Auth::requireUser(['owner','admin','support']);
        if (!self::allowed($user, $permission)) {
            http_response_code(403);
            View::render('error', ['title' => 'دسترسی غیرمجاز', 'message' => 'این مجوز برای حساب شما فعال نیست.']);
            exit;
        }
        return $user;
    }

    public static function authorizePath(string $path): void
    {
        $map = [
            '/admin/users' => 'customers', '/admin/products' => 'catalog', '/admin/discounts' => 'catalog',
            '/admin/orders' => 'sales', '/admin/invoices' => 'sales', '/admin/services' => 'services',
            '/admin/renewals' => 'services', '/admin/tickets' => 'support', '/admin/wallets' => 'finance',
            '/admin/finance' => 'finance', '/admin/taxes' => 'finance', '/admin/notifications' => 'notifications',
            '/admin/audit' => 'audit', '/admin/system' => 'system',
            '/admin/travel' => 'content', '/admin/visa-orders' => 'sales', '/admin/travel-visa-orders' => 'sales',
            '/admin/telegram' => 'telegram', '/admin/travel-alerts' => 'telegram',
        ];
        foreach ($map as $prefix => $permission) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) { self::require($permission); return; }
        }
    }
}
