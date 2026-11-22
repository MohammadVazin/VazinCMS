<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\{Auth,Audit,CacheStore,ContentRevision,Database,SearchIndex,Security,SiteContext,View};
use Throwable;

/** Existing /admin/revisions route. No new surface or tenant gate exception. */
final class ContentRevisionsController
{
    public function handle(): void
    {
        $user=Auth::requireUser(['owner','admin']);
        $pdo=Database::connection();
        $service=new ContentRevision($pdo,SiteContext::id());
        $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
        $pageFilter=max(0,(int)($_GET['page_id']??$_POST['page_id']??0));
        $error=null;$revisions=[];
        header('Cache-Control: no-store');
        if(!in_array($method,['GET','POST'],true)){
            http_response_code(405);header('Allow: GET, POST');return;
        }
        if($method==='POST'){
            try{Security::verifyCsrf();}
            catch(Throwable){
                http_response_code(419);
                $error='درخواست منقضی یا نامعتبر است؛ صفحه را دوباره باز کنید.';
                View::render('revisions',compact('user','revisions','error','pageFilter'));return;
            }
            try{
                $base=(string)($_POST['base_revision']??'');
                if(!preg_match('/\A[a-f0-9]{64}\z/D',$base))throw new \RuntimeException('revision_page_changed');
                $pageId=$service->restore((int)$user['id'],(int)($_POST['revision_id']??0),$base);
                $warning=false;
                try{
                    CacheStore::invalidateTag('content');CacheStore::invalidateTag('page:'.$pageId);
                    SearchIndex::refreshPage($pdo,$pageId);
                    Audit::log('cms.revision_restored','نسخهٔ محتوا همراه ساختار بلوک‌ها بازگردانی شد',
                        (int)$user['id'],['page_id'=>$pageId,'revision_id'=>(int)($_POST['revision_id']??0)]);
                }catch(Throwable){$warning=true;error_log('VazinCMS revision restored; index/cache/audit refresh needs review');}
                header('Location: /admin/revisions?page_id='.$pageId.'&restored=1'.($warning?'&refresh_warning=1':''));return;
            }catch(Throwable $e){
                $code=$e->getMessage();
                if($code==='revision_page_changed'){
                    http_response_code(409);$error='صفحه از زمان باز کردن تاریخچه تغییر کرده است؛ برای جلوگیری از بازنویسی، بازگردانی انجام نشد. صفحه را دوباره باز کنید.';
                }elseif($code==='revision_snapshot_invalid'){
                    http_response_code(409);$error='دادهٔ این نسخه کامل یا معتبر نیست؛ هیچ تغییری در صفحه انجام نشد.';
                }elseif(in_array($code,['revision_unavailable','revision_access_denied'],true)){
                    http_response_code(403);$error='این نسخه در سایت جاری موجود یا برای حساب شما مجاز نیست.';
                }else{
                    http_response_code(500);$error='نتیجهٔ بازگردانی تأیید نشد؛ پیش از تلاش دوباره، وضعیت صفحه را بررسی کنید.';
                }
            }
        }
        try{$revisions=$service->listing((int)$user['id'],$pageFilter);}
        catch(Throwable){http_response_code(403);$error='تاریخچهٔ این صفحه در سایت جاری در دسترس نیست.';}
        View::render('revisions',compact('user','revisions','error','pageFilter'));
    }
}
