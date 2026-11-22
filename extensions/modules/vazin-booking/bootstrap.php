<?php
declare(strict_types=1);

require_once __DIR__ . '/BookingService.php';

use VazinCMS\{Access, ModuleContext, Security, UiLocale, View};
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

    $module->any('/booking', static function () use ($service): void {
        $svc = $service();
        $bookingReceipt = null;
        $bookingError = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $bookingReceipt = $svc->reserve($_POST);
            } catch (\Throwable $e) {
                $bookingError = $e->getMessage();
            }
        }
        View::renderPublic('booking', [
            'locale' => UiLocale::detect(),
            'meta' => ['title' => 'رزرو آنلاین نوبت', 'description' => 'سامانه نوبت‌دهی و رزرو خدمات'],
            'menu' => [],
            'services' => $svc->listServices(true),
            'bookingReceipt' => $bookingReceipt,
            'bookingError' => $bookingError,
        ]);
    });

    $module->any('/admin/booking', static function () use ($service): void {
        $user = Access::require('services');
        $svc = $service();
        $message = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $svc->createService($_POST);
                $message = 'خدمت جدید ذخیره شد.';
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        View::render('booking-admin', [
            'title' => 'نوبت‌دهی و رزروها',
            'user' => $user,
            'services' => $svc->listServices(false),
            'reservations' => $svc->listReservations(50),
            'message' => $message,
            'error' => $error,
        ]);
    });
};
