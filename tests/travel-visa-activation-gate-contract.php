<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/TravelVisaActivationGate.php';
use VazinCMS\TravelVisaActivationGate;

$expect=static fn(bool $ok,string $m)=>$ok?:throw new RuntimeException($m);
foreach([
    'TRAVEL_VISA_COMMERCIAL_ENABLED=false',
    'TRAVEL_VISA_SELLER_ID=',
    'VAZINPAY_GATEWAY_URL=',
    'VAZINPAY_API_KEY=',
    'VAZINPAY_WEBHOOK_SECRET=',
    'TRAVEL_VISA_WEBHOOK_TENANTS=',
] as $v) putenv($v);
$closed=TravelVisaActivationGate::status('travelvisa-cms-sandbox');
$expect(!$closed['ready']&&!$closed['webhook_ready'],'Unconfigured Travel/Visa gate failed open.');

foreach([
    'TRAVEL_VISA_COMMERCIAL_ENABLED=false',
    'TRAVEL_VISA_SELLER_ID=sandbox-operator',
    'VAZINPAY_GATEWAY_URL=https://api.pay.vazin.online',
    'VAZINPAY_API_KEY=sandbox-key',
    'VAZINPAY_WEBHOOK_SECRET=sandbox-secret',
    'TRAVEL_VISA_WEBHOOK_TENANTS=travelvisa-cms-sandbox',
] as $v) putenv($v);
$sandbox=TravelVisaActivationGate::status('travelvisa-cms-sandbox');
$expect(!$sandbox['ready']&&$sandbox['webhook_ready'],'Sandbox callback readiness must not enable commercial sales.');
$expect(!TravelVisaActivationGate::status('other-tenant')['webhook_ready'],'Tenant callback allow-list failed open.');

putenv('TRAVEL_VISA_COMMERCIAL_ENABLED=true');
$ready=TravelVisaActivationGate::status('travelvisa-cms-sandbox');
$expect($ready['ready']&&$ready['webhook_ready'],'Explicit commercial prerequisites were rejected.');
echo "travel-visa-activation-gate-contract: OK\n";
