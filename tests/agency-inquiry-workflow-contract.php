<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . $path);
    if (!is_string($value)) throw new RuntimeException('Could not read workflow contract source: ' . $path);
    return $value;
};

$controller = $read('/src/Controllers/TravelPlatformController.php');
$routes = $read('/extensions/modules/travel/bootstrap.php');
$manifest = $read('/extensions/modules/travel/vazin-extension.json');
$list = $read('/views/travel-agency-inquiries.php');
$detail = $read('/views/travel-agency-inquiry.php');

$check(str_contains($routes, "/admin/agency-inquiries") && str_contains($routes, '->inquiries()') && str_contains($routes, '->inquiry((int)$match[1])'), 'Agency inbox routes are not registered.');
$check(str_contains($manifest, 'درخواست‌های آژانس') && str_contains($manifest, '/admin/agency-inquiries'), 'Agency inbox is missing from Travel navigation.');
$check(str_contains($controller, "private const INQUIRY_STATUSES = ['new', 'reviewing', 'qualified', 'closed']") && str_contains($controller, 'public function inquiries()') && str_contains($controller, 'public function inquiry(int $id)'), 'Agency queue workflow is incomplete.');
$check(str_contains($controller, "Access::require('content')") && str_contains($controller, 'Security::verifyCsrf()') && str_contains($controller, 'assigned_user_id=:assigned_user_id'), 'Agency queue is missing private access, CSRF, or assignment handling.');
$check(str_contains($controller, "Audit::log('travel.agency_inquiry_updated'") && str_contains($controller, "role IN ('owner','admin')"), 'Agency queue changes are not audited or assignment is too broad.');
$check(!str_contains($controller, 'TravelAlertService') && !str_contains($controller, 'TelegramSyncService') && !str_contains($controller, 'VazinPay'), 'Agency queue must not send messages or create sales.');
$check(str_contains($list, 'صف درخواست‌های آژانس') && str_contains($list, 'service_labels') && str_contains($detail, 'گیت‌های آماده‌سازی وایت‌لیبل') && str_contains($detail, 'درخواست آژانس <bdi dir="ltr">'), 'Agency queue views are incomplete or the Persian detail identifier is not direction-isolated.');

echo "Agency inquiry workflow contract: OK\n";
