<?php
declare(strict_types=1);
$root=dirname(__DIR__);$app=(string)file_get_contents($root.'/src/App.php');$controller=(string)file_get_contents($root.'/src/Controllers/TravelVisaPaymentWebhookController.php');
$expect=static fn(bool $ok,string $message)=>$ok?:throw new RuntimeException($message);
$expect(str_contains($app,"/webhooks/v1/travel-visa/"),'Travel/Visa payment callback route is missing.');
$expect(str_contains($controller,'TRAVEL_VISA_WEBHOOK_TENANTS'),'Callback tenant allow-list is missing.');
$expect(str_contains($controller,'65_536'),'Callback payload limit is missing.');
$expect(str_contains($controller,'reconcileWebhook'),'Signed callback is not reconciled through VazinPay readback.');
$expect(!str_contains($controller,'$_POST'),'Callback must verify the raw body rather than parsed form data.');
echo "travel-visa-webhook-endpoint-contract: OK\n";
