<?php
declare(strict_types=1);
namespace VazinCMS;

final class VazinIdClient
{
    public static function configured(): bool { return VazinIdSettings::configured(); }
    public static function begin(string $returnTo='/admin',?int $linkUserId=null): never
    {
        if(!self::configured())throw new \RuntimeException('اتصال Vazin ID هنوز برای این سایت تنظیم نشده است.');
        $state=self::b64(random_bytes(32));$verifier=self::b64(random_bytes(48));
        $_SESSION['vazin_id_oauth'][$state]=['verifier'=>$verifier,'created'=>time(),'return_to'=>LocalReturn::path($returnTo),'link_user_id'=>$linkUserId];
        $settings=VazinIdSettings::current();
        $query=http_build_query(['client_id'=>(string)$settings['client_id'],'redirect_uri'=>self::callbackUrl(),'response_type'=>'code','scope'=>(string)$settings['scopes'],'state'=>$state,'code_challenge'=>self::b64(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256']);
        header('Location: '.self::base().'/oauth/authorize?'.$query,true,302);exit;
    }
    public static function callback():array
    {
        $state=(string)($_GET['state']??'');$flow=$_SESSION['vazin_id_oauth'][$state]??null;unset($_SESSION['vazin_id_oauth'][$state]);
        if(!is_array($flow)||time()-(int)($flow['created']??0)>600)throw new \RuntimeException('درخواست ورود منقضی یا نامعتبر است.');
        if(isset($_GET['error']))throw new \RuntimeException('اجازه ورود از طرف کاربر رد شد.');$code=(string)($_GET['code']??'');if($code==='')throw new \RuntimeException('کد ورود از Vazin ID دریافت نشد.');
        $settings=VazinIdSettings::current();
        $token=self::request('/oauth/token',['grant_type'=>'authorization_code','code'=>$code,'client_id'=>(string)$settings['client_id'],'redirect_uri'=>self::callbackUrl(),'code_verifier'=>(string)$flow['verifier']]);
        $access=(string)($token['access_token']??'');if($access==='')throw new \RuntimeException('Vazin ID توکن معتبر برنگرداند.');
        $profile=self::request('/oauth/userinfo',null,['Authorization: Bearer '.$access]);if(empty($profile['sub']))throw new \RuntimeException('شناسه عمومی Vazin ID دریافت نشد.');
        return ['profile'=>$profile,'return_to'=>LocalReturn::path($flow['return_to']??'/admin'),'link_user_id'=>isset($flow['link_user_id'])?(int)$flow['link_user_id']:null];
    }
    private static function request(string $path,?array $body=null,array $headers=[]):array
    {
        $headers[]='Accept: application/json';$ch=curl_init(self::base().$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false]);
        if($body!==null){$headers[]='Content-Type: application/x-www-form-urlencoded';curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($body));}curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);if(!is_string($raw)||$status<200||$status>=300)throw new \RuntimeException('ارتباط امن با Vazin ID ناموفق بود.');
        $data=json_decode($raw,true);if(!is_array($data))throw new \RuntimeException('پاسخ Vazin ID معتبر نیست.');return $data;
    }
    private static function base():string{$url=(string)(VazinIdSettings::current()['issuer_url']??'');return VazinIdSettings::validIssuer($url)?rtrim($url,'/'):'';}
    private static function callbackUrl():string{return rtrim((string)getenv('APP_URL'),'/').'/auth/vazin-id/callback';}
    private static function b64(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
}
