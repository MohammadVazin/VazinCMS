<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;
use VazinCMS\{UiLocale,View};

final class PayController
{
    private function enabled(): bool {
        return is_file(dirname(__DIR__,2).'/plugins/vazin-pay/enabled.lock');
    }
    private function config(string $key,string $default=''): string {
        $value=(string)getenv($key);return $value!==''?$value:$default;
    }
    private function request(string $method,string $path,?array $body=null): array {
        $apiUrl=rtrim($this->config('VAZINPAY_API_URL'),'/');
        if($apiUrl==='' || !str_starts_with($apiUrl,'https://')) {
            return ['ok'=>false,'status'=>503,'data'=>['error'=>'آدرس امن API وزین‌پی تنظیم نشده است.'],'error'=>'VAZINPAY_API_URL must be an HTTPS URL'];
        }
        $url=$apiUrl.$path;
        $headers=['Authorization: Bearer '.$this->config('VAZINPAY_API_KEY'),'Accept: application/json'];
        if(!empty($_SESSION['vazinpay_portal_token']))$headers[]='X-Vazin-Session: '.$_SESSION['vazinpay_portal_token'];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false]);
        if($body!==null){$headers[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        $data=is_string($raw)?json_decode($raw,true):null;
        return ['ok'=>$status>=200&&$status<300,'status'=>$status,'data'=>is_array($data)?$data:[],'error'=>$error];
    }
    private function locale():string{$locale=UiLocale::detect();UiLocale::boot($locale);return$locale;}
    private function guard(string$locale): void {
        if(!$this->enabled()){http_response_code(503);View::renderPublic('pay-error',['locale'=>$locale,'title'=>UiLocale::message('pay_title'),'message'=>UiLocale::message('pay_module_disabled')]);exit;}
    }
    public function login(): void {$locale=$this->locale();$this->guard($locale);View::renderPublic('pay-login',['locale'=>$locale,'title'=>UiLocale::message('pay_login_title'),'botUsername'=>$this->config('VAZINPAY_BOT_USERNAME','VazinPayBot')]);}
    public function callback(): void {
        $locale=$this->locale();$this->guard($locale);$payload=[];foreach(['id','first_name','last_name','username','photo_url','auth_date','hash'] as $key)if(isset($_GET[$key]))$payload[$key]=(string)$_GET[$key];
        $result=$this->request('POST','/v3/portal/login',$payload);
        if($result['ok']&&!empty($result['data']['session_token'])){session_regenerate_id(true);$_SESSION['vazinpay_portal_token']=$result['data']['session_token'];header('Location: /pay');return;}
        http_response_code($result['status']?:502);View::renderPublic('pay-error',['locale'=>$locale,'title'=>UiLocale::message('pay_login_failed_title'),'message'=>UiLocale::message('pay_login_failed')]);
    }
    public function dashboard(): void {
        $locale=$this->locale();$this->guard($locale);if(empty($_SESSION['vazinpay_portal_token'])){header('Location: /pay/login');return;}
        $result=$this->request('GET','/v3/portal/me');
        if(!$result['ok']){unset($_SESSION['vazinpay_portal_token']);header('Location: /pay/login');return;}
        View::renderPublic('pay-dashboard',['locale'=>$locale,'title'=>UiLocale::message('pay_wallet_title'),'account'=>$result['data']]);
    }
    public function transactions(): void {
        $locale=$this->locale();$this->guard($locale);if(empty($_SESSION['vazinpay_portal_token'])){header('Location: /pay/login');return;}
        $result=$this->request('GET','/v3/portal/transactions?limit=50');
        if(!$result['ok']){unset($_SESSION['vazinpay_portal_token']);header('Location: /pay/login');return;}
        View::renderPublic('pay-transactions',['locale'=>$locale,'title'=>UiLocale::message('pay_transactions_title'),'transactions'=>$result['data']['transactions']??[]]);
    }
    public function logout(): void {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);return;}
        $this->request('POST','/v3/portal/logout',[]);unset($_SESSION['vazinpay_portal_token']);session_regenerate_id(true);header('Location: /pay/login');
    }
}
