<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\Database;
use VazinCMS\Security;
use VazinCMS\TravelAlertService;
use VazinCMS\UiLocale;
use VazinCMS\View;

final class TravelOrderController
{
    private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];

    public function store(string $locale): void
    {
        // Compatibility endpoint: a legacy route must never revive a consumer
        // catalog or checkout while this white-label tenant is fail-closed.
        (new ManualVisaCaseController())->intake($locale);
    }

    public function request(string $locale): void
    {
        (new ManualVisaCaseController())->intake($locale);
    }

    public function account(string $locale): void
    {
        $locale=$this->locale($locale);UiLocale::boot($locale);$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            Security::verifyCsrf(); $id=strtoupper(trim((string)($_POST['public_id']??''))); $code=strtoupper(trim((string)($_POST['access_code']??'')));
            $row=$this->find($id);
            if($row&&hash_equals((string)$row['access_hash'],hash('sha256',$code))){$_SESSION['travel_order_access'][$id]=$code;header('Location: /'.$locale.'/order/'.$id);return;}
            $error=UiLocale::message('visa_tracking_invalid');
        }
        View::renderPublic('account',['locale'=>$locale,'error'=>$error,'meta'=>$this->meta(UiLocale::message('visa_tracking_meta'))]);
    }

    public function order(string $locale,string $id): void
    {
        $locale=$this->locale($locale);UiLocale::boot($locale);$row=$this->find($id); $code=(string)($_SESSION['travel_order_access'][$id]??'');
        if(!$row||$code===''||!hash_equals((string)$row['access_hash'],hash('sha256',$code))){http_response_code(403);header('Location: /'.$locale.'/account');return;}
        $payUrl='';$payError='';$directSalesEnabled=$this->directSalesEnabled();
        if($directSalesEnabled&&(float)$row['amount']>0&&$row['payment_status']!=='paid'){
            $origin=$this->paymentApiBase();$apiKey=$this->paymentApiKey();
            if(str_starts_with($origin,'https://')&&$apiKey!==''){
                $invoiceId=trim((string)($row['payment_reference']??''));$invoice=[];
                if($invoiceId!==''&&preg_match('/^[A-Za-z0-9_-]{8,100}$/',$invoiceId))$invoice=$this->vazinRequest('POST',$origin.'/v1/invoices/'.rawurlencode($invoiceId).'/check',$apiKey,[]);
                if(!$invoice){$invoice=$this->vazinRequest('POST',$origin.'/v1/invoices',$apiKey,['external_order_id'=>$id,'amount'=>(float)$row['amount'],'currency'=>(string)$row['currency'],'description'=>(string)($row['product_title']?:'Vazin Visa service')],hash('sha256','visa-order-'.$id));$invoiceId=(string)($invoice['invoice_id']??'');if($invoiceId!=='')Database::connection()->prepare("UPDATE travel_orders SET payment_provider='vazinpay',payment_reference=:ref,updated_at=CURRENT_TIMESTAMP WHERE public_id=:id AND payment_status<>'paid'")->execute(['ref'=>$invoiceId,'id'=>$id]);}
                if($this->validPaidInvoice($invoice,$row)){$this->markPaid($id,(string)$invoice['invoice_id']);$row=$this->find($id);}
                else{$checkout=(string)($invoice['payment_url']??'');if($checkout!=='')$payUrl=str_starts_with($checkout,'https://')?$checkout:$origin.'/'.ltrim($checkout,'/');else $payError=UiLocale::message('visa_gateway_invalid');}
            }else $payError=UiLocale::message('visa_pay_not_configured');
        }
        $alertOptIn=['available'=>false,'status'=>'unavailable'];try{$alertOptIn=TravelAlertService::customerOptIn($row);}catch(\Throwable$alertError){error_log('[VazinCMS] visa customer alert availability failed type='.$alertError::class);}
        View::renderPublic('order-status',['locale'=>$locale,'order'=>$row,'accessCode'=>$code,'payUrl'=>$payUrl,'payError'=>$payError,'directSalesEnabled'=>$directSalesEnabled,'alertOptIn'=>$alertOptIn,'meta'=>$this->meta(UiLocale::message('visa_order_meta',['id'=>$id]))]+(new VisaCaseController())->publicData($row));
    }

    public function paymentCallback(): void
    {
        if(!$this->directSalesEnabled()){http_response_code(410);header('Cache-Control: no-store');return;}
        $intent=trim((string)($_GET['invoice_id']??$_GET['intent_id']??''));$origin=$this->paymentApiBase();$key=$this->paymentApiKey();
        if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/',$intent)||!str_starts_with($origin,'https://')||$key===''){http_response_code(400);$locale=UiLocale::detect();UiLocale::boot($locale);echo htmlspecialchars(UiLocale::message('payment_invalid'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');return;}
        $data=$this->vazinRequest('POST',$origin.'/v1/invoices/'.rawurlencode($intent).'/check',$key,[]);$id=(string)($data['external_order_id']??'');$row=$this->find($id);if($row&&$this->validPaidInvoice($data,$row))$this->markPaid($id,$intent);
        $locale=$this->locale((string)($row['locale']??'fa'));header('Location: /'.$locale.($row&&isset($_SESSION['travel_order_access'][$id])?'/order/'.$id.'?payment=checked':'/account'));
    }

    /**
     * Legacy validation contract retained for older extension tests only.
     * Public routes delegate to ManualVisaCaseController and never consume
     * this data to create a product, invoice, supplier request or checkout.
     */
    private function validated(array &$errors): array
    {
        $v=static fn(string $k):string=>trim((string)($_POST[$k]??''));
        $service=$v('service_type');$name=$v('full_name');$phone=$v('phone');$email=$v('email');$travelers=max(1,min(50,(int)($_POST['travelers']??1)));
        if(!in_array($service,['visa','tour','hotel','flight','consultation'],true))$errors[]=UiLocale::message('visa_service_invalid');
        if(mb_strlen($name)<3||mb_strlen($name)>190)$errors[]=UiLocale::message('visa_name_invalid');
        if(mb_strlen($phone)<6||mb_strlen($phone)>64)$errors[]=UiLocale::message('visa_phone_invalid');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$errors[]=UiLocale::message('visa_email_invalid');
        return ['service_type'=>$service,'full_name'=>$name,'email'=>$email?:null,'phone'=>$phone,'destination'=>$v('destination')?:null,'nationality'=>$v('nationality')?:null,'travelers'=>$travelers,'travel_date'=>$v('travel_date')?:null,'notes'=>mb_substr($v('notes'),0,3000)?:null];
    }

    private function find(string $id): array|false{$s=Database::connection()->prepare('SELECT * FROM travel_orders WHERE public_id=:id LIMIT 1');$s->execute(['id'=>$id]);return $s->fetch();}
    private function validPaidInvoice(array $invoice,array $order):bool{return ($invoice['status']??'')==='paid'&&(string)($invoice['external_order_id']??'')===(string)$order['public_id']&&strtoupper((string)($invoice['currency']??''))===strtoupper((string)$order['currency'])&&abs((float)($invoice['amount']??0)-(float)$order['amount'])<0.001;}
    private function markPaid(string $id,string $reference):void{Database::connection()->prepare("UPDATE travel_orders SET payment_status='paid',status=CASE WHEN status IN ('new','awaiting_payment') THEN 'paid' ELSE status END,payment_provider='vazinpay',payment_reference=:ref,updated_at=CURRENT_TIMESTAMP WHERE public_id=:id AND payment_status<>'paid'")->execute(['ref'=>$reference,'id'=>$id]);}
    private function locale(string $v):string{return in_array($v,['fa','ru','en','ar'],true)?$v:UiLocale::detect();}
    private function meta(string $title):array{$base=rtrim((string)(getenv('APP_URL')?:$this->publicBase()),'/');return ['title'=>$title.' | Vazin Travel','description'=>UiLocale::message('visa_meta_description'),'canonical'=>$base.(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/')];}
    private function publicBase():string{return (($_SERVER['HTTP_X_FORWARDED_PROTO']??'https')==='http'?'http':'https').'://'.($_SERVER['HTTP_HOST']??'localhost');}
    private function directSalesEnabled():bool{return filter_var((string)(getenv('VAZIN_VISA_DIRECT_SALES_ENABLED')?:'false'),FILTER_VALIDATE_BOOLEAN);}
    private function paymentApiBase():string
    {
        $configured=rtrim(trim((string)(getenv('VAZINPAY_API_URL')?:'')),'/');
        if($configured!=='')return $configured;
        // Compatibility for 10.0.0 installations where the payment endpoint was
        // mistakenly stored under VAZIN_ID_URL. Never treat the real ID service
        // as a payment API.
        $legacy=rtrim(trim((string)(getenv('VAZIN_ID_URL')?:'')),'/');
        $host=strtolower((string)(parse_url($legacy,PHP_URL_HOST)?:''));
        return $host!==''&&$host!=='id.vazin.online'?$legacy:'';
    }
    private function paymentApiKey():string{return trim((string)(getenv('VAZINPAY_PROJECT_API_KEY')?:getenv('VAZINPAY_MERCHANT_API_KEY')?:''));}
    private function vazinRequest(string $method,string $url,string $key,?array $body=null,string $idempotency=''):array{$headers=['Authorization: Bearer '.$key,'Accept: application/json'];if($idempotency!=='')$headers[]='Idempotency-Key: '.$idempotency;$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false]);if($body!==null){$headers[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);$raw=curl_exec($ch);$data=json_decode(is_string($raw)?$raw:'',true);return is_array($data)?$data:[];}
}
