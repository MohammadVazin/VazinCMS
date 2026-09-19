<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . $path);
    if (!is_string($value)) throw new RuntimeException('Could not read contract source: ' . $path);
    return $value;
};

$manual = $read('/src/Controllers/ManualVisaCaseController.php');
$visaRoutes = $read('/extensions/modules/visa/bootstrap.php');
$travelRoutes = $read('/extensions/modules/travel/bootstrap.php');
$orders = $read('/src/Controllers/TravelOrderController.php');
$statusView = $read('/views/public/order-status.php');
$intakeView = $read('/views/public/visa-manual-intake.php');
$application = $read('/src/VisaApplicationService.php');
$caseController = $read('/src/Controllers/VisaCaseController.php');
$caseView = $read('/views/visa-case.php');
$environment = $read('/.env.example');

$check(str_contains($visaRoutes, 'ManualVisaCaseController') && str_contains($visaRoutes, '/visa(?:/intake)?'), 'Public Visa route is not bound to the manual case controller.');
$check(str_contains($travelRoutes, '/request$#') && str_contains($travelRoutes, 'ManualVisaCaseController'), 'Legacy public request route is not bound to manual review.');
$check(str_contains($manual, 'payment_status,product_code,product_title') && str_contains($manual, 'not_required') && str_contains($manual, 'manual-evisa'), 'Manual eVisa cases are not marked as no-payment cases.');
$check(!str_contains($manual, '/v1/invoices') && !str_contains($manual, 'VazinPay'), 'Manual case creation must not create a payment checkout.');
$check(!str_contains($intakeView, 'VazinPay') && !str_contains($intakeView, 'visa_products'), 'Manual intake view contains a direct-sales surface.');
$check(str_contains($manual, "'intakeCopy' => \$intakeCopy") && str_contains($intakeView, '$intakeCopy[') && !str_contains($intakeView, '$copy['), 'Manual intake copy can collide with active-theme chrome.');
$check(str_contains($orders, 'VAZIN_VISA_DIRECT_SALES_ENABLED') && str_contains($orders, 'if(!$this->directSalesEnabled()){http_response_code(410)'), 'Legacy payment callback is not fail-closed.');
$check(substr_count($orders, '(new ManualVisaCaseController())->intake($locale);') === 2 && !str_contains($orders, 'visa_products'), 'Legacy public methods can still create a product checkout.');
$check(str_contains($statusView, '$directSalesEnabled&&$payUrl') && str_contains($statusView, 'بررسی دستی آژانس'), 'Public case status does not hide checkout when sales are disabled.');
$check(str_contains($environment, 'VAZIN_VISA_DIRECT_SALES_ENABLED=false'), 'Direct Visa sales are not disabled by default.');
$check(str_contains($application, 'function forOperator') && str_contains($application, 'SecretStore::open') && str_contains($application, "return ['state' => 'available', 'metadata' => \$metadata, 'payload' => \$payload];"), 'Operator decryptor contract is incomplete.');
$check(str_contains($caseController, "Auth::requireUser(['owner','admin'])") && str_contains($caseController, 'Cache-Control: no-store, private'), 'Operator intake view is not restricted or non-cacheable.');
$check(str_contains($caseView, 'اطلاعات محرمانهٔ eVisa') && str_contains($caseView, '$manualCase'), 'Operator case view is missing protected intake or manual-case rendering.');

echo "Manual Visa no-checkout contract: OK\n";
