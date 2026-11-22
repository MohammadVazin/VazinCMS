<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Audit,Auth,CacheStore,Database,Security,SiteContext,SiteTemplateBuilder,View};

final class SiteTemplateBuilderController
{
    public function handle():void
    {
        $user=Auth::requireUser(['owner','admin']);
        header('Cache-Control: no-store');
        try{
            $service=new SiteTemplateBuilder(Database::connection(),SiteContext::id());
            $state=$service->read((int)$user['id']);
        }catch(Throwable){
            http_response_code(403);
            View::render('error',['title'=>'قالب‌ساز','message'=>'دسترسی یا ساختار قالب این سایت تأیید نشد.']);
            return;
        }
        $error=null;$conflict=false;
        $message=($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&isset($_GET['saved'])?'تغییرات قالب ذخیره شد. برای تغییر سایت عمومی، قالب را فعال کنید.':null;
        $selectedKey=(string)($_GET['template']??$state['active_template']);
        if(!isset($state['templates'][$selectedKey]))$selectedKey=$state['active_template'];
        $template=$state['templates'][$selectedKey];
        $formVersion=$state['version'];
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            try{Security::verifyCsrf();}
            catch(Throwable){
                http_response_code(419);
                View::render('error',['title'=>'درخواست معتبر نیست','message'=>'صفحه را دوباره باز کنید و درخواست را تکرار کنید.']);
                return;
            }
            $expected=is_string($_POST['template_version']??null)?$_POST['template_version']:'';
            $formVersion=$expected;
            $action=is_string($_POST['action']??null)?$_POST['action']:'save';
            try{
                if(!is_string($_GET['template']??null)||!isset($state['templates'][$_GET['template']])||
                   !is_string($_POST['template_key']??null)||$_POST['template_key']!==$_GET['template']){
                    throw new \RuntimeException('template_target_invalid');
                }
                if(in_array($action,['save','save_activate'],true)){
                    $raw=(string)($_POST['template_payload']??'');
                    if(strlen($raw)>24000)throw new \RuntimeException('template_payload_too_large');
                    $payload=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
                    if(!is_array($payload))throw new \RuntimeException('template_payload_invalid');
                    $state=$service->saveTemplate((int)$user['id'],$selectedKey,$payload,$expected,$action==='save_activate');
                    $template=$state['templates'][$selectedKey];
                }elseif($action==='activate'){
                    $state=$service->activate((int)$user['id'],$selectedKey,$expected);
                    $template=$state['templates'][$selectedKey];
                }elseif($action==='duplicate'){
                    $before=array_keys($state['templates']);
                    $state=$service->duplicate((int)$user['id'],$selectedKey,(string)($_POST['new_template_name']??''),$expected);
                    $created=array_values(array_diff(array_keys($state['templates']),$before));
                    $selectedKey=$created[0]??$selectedKey;
                    $template=$state['templates'][$selectedKey];
                }elseif($action==='restore'){
                    $state=$service->restore((int)$user['id'],$expected);
                    $selectedKey=$state['active_template'];$template=$state['templates'][$selectedKey];
                }elseif($action==='reset'){
                    $state=$service->resetTemplate((int)$user['id'],$selectedKey,$expected);
                    $template=$state['templates'][$selectedKey];
                }elseif($action==='delete'){
                    $state=$service->deleteCustom((int)$user['id'],$selectedKey,$expected);
                    $selectedKey=$state['active_template'];$template=$state['templates'][$selectedKey];
                }else{
                    throw new \RuntimeException('template_action_invalid');
                }
                try{
                    CacheStore::invalidateTag('settings');CacheStore::invalidateTag('content');
                    Audit::log('cms.template_builder_changed','ساختار قالب سایت تغییر کرد',(int)$user['id'],[
                        'site_id'=>SiteContext::id(),'action'=>$action,'template_key'=>$selectedKey
                    ]);
                }catch(Throwable){error_log('[VazinCMS template] cache/audit refresh unavailable');}
                header('Location: /admin/template-builder?template='.rawurlencode($selectedKey).'&saved=1',true,303);
                return;
            }catch(Throwable $failure){
                $code=$failure->getMessage();$conflict=$code==='template_changed';
                http_response_code($conflict?409:($code==='template_access_denied'?403:422));
                $error=match($code){
                    'template_changed'=>'قالب در برگهٔ دیگری تغییر کرده است. تغییرات شما حفظ شد اما ذخیره نشد؛ نسخهٔ جاری را دوباره باز کنید.',
                    'template_access_denied'=>'اجازهٔ تغییر قالب این سایت را ندارید.',
                    'template_component_limit'=>'تعداد اجزای قابل استفاده در این قالب به سقف مجاز رسیده است.',
                    'template_previous_unavailable'=>'نسخهٔ قبلی قالب برای بازگردانی وجود ندارد.',
                    default=>'قالب ذخیره نشد؛ اجزا، پیوندها و چیدمان را بررسی کنید.'
                };
                if(isset($payload)&&is_array($payload)){
                    try{$template=SiteTemplateBuilder::validateTemplate($payload);}catch(Throwable){}
                }
            }
        }
        $templates=$state['templates'];$version=$formVersion;
        $activeKey=$state['active_template'];$previousAvailable=is_array($state['previous']);
        View::render('template-builder',compact(
            'user','state','templates','template','selectedKey','activeKey','version',
            'previousAvailable','message','error','conflict'
        ));
    }
}
