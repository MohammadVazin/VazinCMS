<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\ApiAuth;
use VazinCMS\Database;
use VazinCMS\Version;
use VazinCMS\ModuleRegistry;

final class ApiController
{
    public function health(): void { $this->run('read',fn()=>['service'=>'Vazin Online API','version'=>Version::current()]); }
    public function customers(): void { $this->run('read',fn()=>['customers'=>Database::connection()->query("SELECT id,name,email,country,status,created_at FROM users WHERE role='client' ORDER BY id DESC LIMIT 200")->fetchAll()]); }
    public function invoices(): void { $this->run('billing',fn()=>['invoices'=>Database::connection()->query('SELECT id,invoice_number,user_id,total,currency,status,due_at,paid_at,created_at FROM invoices ORDER BY id DESC LIMIT 200')->fetchAll()]); }
    public function services(): void { $this->run('services',fn()=>['services'=>Database::connection()->query('SELECT id,user_id,product_id,name,status,expires_at,auto_renew,billing_cycle,renewal_price,currency FROM services ORDER BY id DESC LIMIT 200')->fetchAll()]); }
    public function wallets(): void { $this->run('wallet',fn()=>['wallets'=>Database::connection()->query('SELECT id,user_id,currency,balance,updated_at FROM wallets ORDER BY id DESC LIMIT 200')->fetchAll()]); }
    public function systemV2(): void { $this->run('read',fn()=>['api_version'=>'2.0','platform'=>['name'=>'Vazin Online','version'=>Version::current()],'capabilities'=>['customers','billing','wallets','services','modules','webhooks']]); }
    public function modulesV2(): void { $this->run('modules',fn()=>['api_version'=>'2.0','modules'=>ModuleRegistry::all()]); }
    public function customersV2(): void { $this->run('customers',fn()=>['api_version'=>'2.0','customers'=>Database::connection()->query("SELECT id,name,email,phone,country,status,created_at,updated_at FROM users WHERE role='client' ORDER BY id DESC LIMIT 500")->fetchAll()]); }
    public function ordersV2(): void { $this->run('orders',fn()=>['api_version'=>'2.0','orders'=>Database::connection()->query('SELECT id,order_number,user_id,product_id,product_name,amount,currency,status,created_at,updated_at FROM orders ORDER BY id DESC LIMIT 500')->fetchAll()]); }
    public function moduleHeartbeat(string $key): void { $this->run('modules',function()use($key){if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new \InvalidArgumentException('method_not_allowed');$payload=json_decode((string)file_get_contents('php://input'),true);return ['heartbeat'=>ModuleRegistry::heartbeat($key,is_array($payload)?$payload:[])];}); }
    private function run(string $scope,callable $handler): never
    {
        $started=microtime(true);$token=null;$status=200;
        try{$token=ApiAuth::authorize($scope);$payload=['ok'=>true]+$handler();}
        catch(\Throwable $e){$status=500;$payload=['ok'=>false,'error'=>['code'=>'server_error','message'=>'خطای داخلی API']];error_log('[VazinCMS API '.Version::current().'] '.$e);}
        if($token){try{$pdo=Database::connection();$pdo->prepare('INSERT INTO api_request_logs(token_id,method,path,status_code,ip_address,duration_ms) VALUES(:token,:method,:path,:status,:ip,:duration)')->execute(['token'=>$token['id'],'method'=>substr((string)($_SERVER['REQUEST_METHOD']??'GET'),0,10),'path'=>substr((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/'),0,255),'status'=>$status,'ip'=>substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),'duration'=>(int)round((microtime(true)-$started)*1000)]);}catch(\Throwable $e){error_log('[VazinCMS API log] '.$e);}}
        ApiAuth::respond($payload,$status);
    }
}
