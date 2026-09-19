<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;
use VazinCMS\{Access,ApiAuth,Database,View};
final class TravelVisaAdminController
{
 public function index():void{$user=Access::require('sales');$pdo=Database::connection();$orders=$pdo->query('SELECT public_ref,tenant_key,service_code,amount,currency,payment_status,lifecycle_status,created_at,updated_at FROM travel_visa_orders ORDER BY id DESC LIMIT 200')->fetchAll();$stats=['all'=>count($orders),'paid'=>count(array_filter($orders,fn($o)=>$o['payment_status']==='paid')),'processing'=>count(array_filter($orders,fn($o)=>$o['lifecycle_status']==='processing')),'refunded'=>count(array_filter($orders,fn($o)=>$o['payment_status']==='refunded'))];View::render('travel-visa-orders',compact('user','orders','stats'));}
 public function api():never{try{ApiAuth::authorize('travel_visa.read');$rows=Database::connection()->query('SELECT public_ref,tenant_key,service_code,amount,currency,payment_status,lifecycle_status,invoice_id,processing_started_at,created_at,updated_at FROM travel_visa_orders ORDER BY id DESC LIMIT 200')->fetchAll();ApiAuth::respond(['ok'=>true,'api_version'=>'1.0','orders'=>$rows]);}catch(\Throwable){ApiAuth::respond(['ok'=>false,'error'=>['code'=>'travel_visa_unavailable','message'=>'دسترسی یا سرویس سفر و ویزا در دسترس نیست.']],403);}}
}
