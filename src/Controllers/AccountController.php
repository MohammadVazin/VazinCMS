<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;
use VazinCMS\{Auth,Database,View,VazinIdClient,SiteScopedRuntimePolicy};
final class AccountController{
 private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];
 public function dashboard(string $locale):void{
  $pdo=Database::connection();
  if(SiteScopedRuntimePolicy::scopedMode($pdo)){
    // Customer orders, invoices and services are still global. A site-
    // scoped admin identity must not expose another customer's records
    // through this legacy account route.
    http_response_code(409);
    header('Cache-Control: no-store');
    View::render('error',[
      'title'=>'حساب مشتریان هنوز تفکیک نشده است',
      'message'=>'نمایش اطلاعات خرید و خدمات تا تکمیل جداسازی سایت‌ها غیرفعال است.'
    ]);
    return;
  }
  $locale=in_array($locale,['fa','ru','en'],true)?$locale:\VazinCMS\UiLocale::detect();
  $user=Auth::user();
  if(!$user)VazinIdClient::begin('/'.$locale.'/my-account');
  $s=$pdo->prepare('SELECT id,order_number,product_name,amount,currency,status,created_at FROM orders WHERE user_id=:id ORDER BY id DESC LIMIT 50');$s->execute(['id'=>$user['id']]);$orders=$s->fetchAll();
  $s=$pdo->prepare('SELECT id,name,status,started_at,expires_at FROM services WHERE user_id=:id ORDER BY id DESC LIMIT 50');$s->execute(['id'=>$user['id']]);$services=$s->fetchAll();
  $s=$pdo->prepare('SELECT id,invoice_number,total,currency,status,due_at,created_at FROM invoices WHERE user_id=:id ORDER BY id DESC LIMIT 50');$s->execute(['id'=>$user['id']]);$invoices=$s->fetchAll();
  View::renderPublic('my-account',['locale'=>$locale,'user'=>$user,'orders'=>$orders,'services'=>$services,'invoices'=>$invoices,'meta'=>['title'=>'حساب من | VazinCMS','description'=>'سفارش‌ها و خدمات حساب مرکزی','canonical'=>rtrim((string)getenv('APP_URL'),'/').'/'.$locale.'/my-account']]);
 }
}
