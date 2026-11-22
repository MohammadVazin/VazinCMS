<?php
declare(strict_types=1);

require_once __DIR__ . '/BookingService.php';

use VazinCMS\{Access, ModuleContext, Security};
use VazinCMS\Modules\Booking\BookingService;

return static function (ModuleContext $module): void {
    $service = static fn(): BookingService => new BookingService($module->db());
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $module->registerBlock('service-scheduler', [
        'label' => 'جدول خدمات قابل رزرو',
        'category' => 'booking',
        'schema' => ['heading' => 'string'],
    ], static function (array $data) use ($service, $esc): string {
        $heading = trim((string) ($data['heading'] ?? 'رزرو آنلاین خدمات'));
        $services = $service()->listServices(true);
        $html = '<section class="vz-booking-widget"><h2>' . $esc($heading) . '</h2><div class="vz-booking-list">';
        foreach ($services as $s) {
            $html .= '<article class="vz-booking-item"><h3>' . $esc((string) $s['title']) . '</h3>'
                . '<span>' . (int) $s['duration_minutes'] . ' دقیقه</span>'
                . '<a href="/booking?service=' . (int) $s['id'] . '">انتخاب زمان</a></article>';
        }
        return $html . '</div></section>';
    });

    $module->api('GET', 'services', static fn(): array => ['items' => $service()->listServices(true)]);
    $module->api('POST', 'reserve', static fn(array $input): array => $service()->reserve($input));

    $module->get('/booking', static function () use ($service, $esc): void {
        $services = $service()->listServices(true);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>رزرو نوبت</title></head><body><main><h1>سامانه رزرو آنلاین</h1>';
        foreach ($services as $s) {
            echo '<article><h2>' . $esc((string) $s['title']) . '</h2><p>' . $esc((string) $s['description']) . '</p></article>';
        }
        echo '</main></body></html>';
    });

    $module->any('/admin/booking', static function () use ($service): void {
        Access::require('services');
        $svc = $service();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $svc->createService($_POST);
        }
        $res = $svc->listReservations(50);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>مدیریت نوبت‌ها</title></head><body><main><h1>نوبت‌های ثبت‌شده (' . count($res) . ')</h1></main></body></html>';
    });
};
