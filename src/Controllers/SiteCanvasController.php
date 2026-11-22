<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;
use Throwable;
use VazinCMS\{Auth,Security,Database,SiteContext,SiteTemplateBuilder,SiteCanvas,View,CacheStore};

final class SiteCanvasController
{
    public function handle():void
    {
        $user=Auth::requireUser(['owner','admin']);header('Cache-Control: no-store');
        $actor=(int)$user['id'];
        try{
            $pdo=Database::connection();$site=SiteContext::id();
            $catalog=(new SiteTemplateBuilder($pdo,$site))->read($actor);
            $service=new SiteCanvas($pdo,$site);$state=$service->read($actor);
            $pages=$service->pages();
        }catch(Throwable){http_response_code(403);View::render('error',['title'=>'طراحی سایت','message'=>'دسترسی این سایت تأیید نشد.']);return;}
        $key=(string)($_GET['template']??$catalog['active_template']);
        $kind=(string)($_GET['kind']??'page');
        $component=(string)($_GET['component']??'');
        $pageId=(int)($_GET['page_id']??0);$pageInfo=null;
        if(!isset($catalog['templates'][$key])||
            !in_array($kind,['page','archive','header','footer','component'],true)||
            ($kind==='component'&&preg_match('/\As-[0-9a-f]{8}\z/D',$component)!==1)||
            ($kind!=='page'&&$pageId!==0)){
            http_response_code(404);View::render('error',['title'=>'طراحی پیدا نشد','message'=>'شناسهٔ قالب، بخش یا صفحه معتبر نیست.']);return;
        }
        if($pageId>0){
            try{$pageInfo=$service->pageInfo($pageId);}
            catch(Throwable){http_response_code(404);View::render('error',['title'=>'صفحه پیدا نشد','message'=>'این صفحه در سایت فعلی وجود ندارد یا قابل ویرایش نیست.']);return;}
        }
        $usingPageOverride=$pageId>0&&isset($state['snapshot']['pageLayouts'][(string)$pageId]);
        if($kind==='component'){
            $nodes=$state['snapshot']['shared'][$component]['nodes']??[];
        }elseif($pageId>0){
            $nodes=$usingPageOverride?$state['snapshot']['pageLayouts'][(string)$pageId]:
                ($state['snapshot']['layouts'][$key]['page']??SiteCanvas::defaults('page'));
        }else{
            $nodes=$state['snapshot']['layouts'][$key][$kind]??SiteCanvas::defaults($kind);
        }
        $canvasLabel=$kind==='component'?($state['snapshot']['shared'][$component]['label']??'جزء همگام جدید'):'';
        $base=$state['version'];$error=null;$conflict=false;$message=null;
        $canvasTarget=$key.'|'.$kind.'|'.$component.'|'.$pageId;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            try{Security::verifyCsrf();}catch(Throwable){http_response_code(419);View::render('error',['title'=>'درخواست نامعتبر','message'=>'فرم را دوباره باز کنید.']);return;}
            $base=is_string($_POST['canvas_version']??null)?$_POST['canvas_version']:'';
            try{
                if(!is_string($_POST['canvas_target']??null)||!hash_equals($canvasTarget,$_POST['canvas_target']))throw new \RuntimeException('canvas_target_invalid');
                $action=(string)($_POST['action']??'save');
                if($action==='save'){
                    $raw=$_POST['nodes']??null;
                    if(!is_string($raw)||strlen($raw)>120000)throw new \RuntimeException('canvas_payload_invalid');
                    $attempt=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                    $validated=SiteCanvas::tree($attempt,$kind,$state['snapshot']['shared']);$nodes=$validated;
                    if($kind==='component'){
                        $canvasLabel=is_string($_POST['label']??null)?$_POST['label']:'';
                        $service->saveShared($actor,$base,$component,$canvasLabel,$nodes);
                    }elseif($pageId>0){
                        $service->savePage($actor,$base,$pageId,$nodes);
                    }else{$service->save($actor,$base,$key,$kind,$nodes);}
                }elseif($action==='restore'){
                    $service->restore($actor,$base,(string)($_POST['history_id']??''));
                }elseif($action==='reset'&&$kind!=='component'){
                    if($pageId>0)$service->resetPage($actor,$base,$pageId);
                    else $service->reset($actor,$base,$key,$kind);
                }else throw new \RuntimeException('canvas_action_invalid');
                try{CacheStore::invalidateTag('settings');CacheStore::invalidateTag('content');}catch(Throwable){}
                $query='template='.rawurlencode($key).'&kind='.$kind.($component!==''?'&component='.$component:'').($pageId>0?'&page_id='.$pageId:'');
                header('Location: /admin/layouts?'.$query.'&saved=1',true,303);return;
            }catch(Throwable $e){
                $conflict=$e->getMessage()==='canvas_changed';http_response_code($conflict?409:422);
                $error=$conflict?'نسخهٔ سرور تغییر کرده؛ تغییرهای این فرم ذخیره نشد. فرم را دوباره باز کنید.':'ذخیره نشد؛ ساختار، پیوندها، محدودیت عمق و جایگاه محتوای اصلی را بررسی کنید.';
            }
        }elseif(isset($_GET['saved']))$message='طراحی ذخیره شد. نسخهٔ قبلی در تاریخچهٔ یکپارچه محفوظ است.';
        $templates=$catalog['templates'];$shared=$state['snapshot']['shared'];$history=$state['history'];
        View::render('site-canvas',compact('nodes','canvasLabel','kind','key','component','pageId','pageInfo','pages','usingPageOverride','canvasTarget','base','error','conflict','message','templates','shared','history'));
    }
}
