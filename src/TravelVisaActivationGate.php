<?php
declare(strict_types=1);
namespace VazinCMS;
/** Explicit, inspectable commercial-release gate; absence of any item keeps sales closed. */
final class TravelVisaActivationGate
{
 public static function status(string $tenant):array{$required=['TRAVEL_VISA_COMMERCIAL_ENABLED','TRAVEL_VISA_SELLER_ID','VAZINPAY_API_URL','VAZINPAY_PROJECT_API_KEY','VAZINPAY_WEBHOOK_SECRET','TRAVEL_VISA_WEBHOOK_TENANTS'];$missing=[];foreach($required as$key)if(trim((string)getenv($key))==='')$missing[]=$key;$enabled=filter_var((string)getenv('TRAVEL_VISA_COMMERCIAL_ENABLED'),FILTER_VALIDATE_BOOLEAN);$tenants=array_map('trim',explode(',',(string)getenv('TRAVEL_VISA_WEBHOOK_TENANTS')));if(!in_array($tenant,$tenants,true))$missing[]='tenant_webhook_allowlist';return ['ready'=>$enabled&&$missing===[],'missing'=>array_values(array_unique($missing))];}
}
