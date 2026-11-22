<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;
use Throwable;
use VazinCMS\{Auth,Audit,CacheStore,Database,Security,SiteContext,SiteDesign,View};

final class SiteDesignController
{
    public function handle():void
    {
        $user=Auth::requireUser(['owner','admin']);
        header('Cache-Control: no-store');
        $error=null;$conflict=false;$message=isset($_GET['saved'])?'طرح سایت ذخیره شد. پیش‌نمایش‌های ذخیره‌نشده، سایت را تغییر نمی‌دهند.':null;
        try{
            $service=new SiteDesign(Database::connection(),SiteContext::id());
            $state=$service->read((int)$user['id']);
        }catch(Throwable){
            http_response_code(403);
            View::render('error',['title'=>'طراحی سایت','message'=>'دسترسی یا ساختار تنظیمات این سایت تأیید نشد.']);return;
        }
        $values=$state['values'];$formVersion=$state['version'];
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            try{Security::verifyCsrf();}catch(Throwable){http_response_code(419);View::render('error',['title'=>'درخواست معتبر نیست','message'=>'صفحه را دوباره باز کنید و درخواست را تکرار کنید.']);return;}
            $submitted=is_array($_POST['design']??null)?$_POST['design']:[];
            $formVersion=is_string($_POST['design_version']??null)?$_POST['design_version']:'';
            foreach($values as $key=>$default){
                if(isset($submitted[$key])&&is_string($submitted[$key]))$values[$key]=mb_substr($submitted[$key],0,80);
            }
            try{
                $service->save((int)$user['id'],$submitted,$formVersion,(string)($_POST['action']??'save'));
                try{
                    CacheStore::invalidateTag('settings');CacheStore::invalidateTag('content');
                    Audit::log('cms.site_design_saved','تنظیمات طراحی سایت ذخیره شد',(int)$user['id'],['site_id'=>SiteContext::id()]);
                }catch(Throwable){error_log('[VazinCMS design] post-save cache/audit refresh unavailable');}
                header('Location: /admin/design?saved=1',true,303);return;
            }catch(Throwable $e){
                $code=$e->getMessage();$conflict=$code==='design_changed';
                http_response_code($conflict?409:($code==='design_access_denied'?403:422));
                $error=match($code){
                    'design_changed'=>'طرح سایت در برگهٔ دیگری تغییر کرده است. تغییر شما حفظ شد اما ذخیره نشد؛ طرح جاری را دوباره باز کنید.',
                    'design_contrast_too_low'=>'رنگ متن و پس‌زمینه یا دکمه به‌اندازهٔ کافی قابل تفکیک نیست. ترکیب خواناتری انتخاب کنید.',
                    'design_previous_unavailable'=>'هنوز طرح قبلی برای بازگردانی وجود ندارد.',
                    'design_access_denied'=>'اجازهٔ تغییر طراحی این سایت را ندارید.',
                    default=>'طرح ذخیره نشد؛ فقط رنگ‌ها و اندازه‌های مجاز را انتخاب کنید.'
                };
            }
        }
        $presets=SiteDesign::presets();
        View::render('site-design',compact('user','state','values','formVersion','presets','message','error','conflict'));
    }
}
