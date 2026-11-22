<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Audit,Auth,Database,Security,SiteContext,SiteScopedSeoService,View};

/** Candidate-only scoped SEO UI. No global reports or notifications. */
final class SiteScopedSeoController
{
    public function index(): void
    {
        $user=Auth::requireUser(['owner','admin']);
        try {
            $seo=new SiteScopedSeoService(Database::connection(),SiteContext::id());
            // A global owner role is never enough: verify explicit site membership
            // before either rendering counts or accepting POST actions.
            $data=$seo->dashboard((int)$user['id']);
        } catch(Throwable $error) {
            $denied=$error->getMessage()==='tenant_access_denied';
            http_response_code($denied?403:409);
            View::render('error',[
                'title'=>$denied?'دسترسی سایت تأیید نشد':'گزارش سایت هنوز آماده نیست',
                'message'=>$denied
                    ? 'برای مشاهده گزارش‌ها عضویت تأییدشده در این سایت لازم است.'
                    : 'جداسازی گزارش‌های سئو و داده‌های سایت در پایگاه داده هنوز کامل نشده است.'
            ]);
            return;
        }

        $error=null;$message=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            Security::verifyCsrf();
            try{
                $action=(string)($_POST['action']??'analyze_all');
                $actorId=(int)$user['id'];
                if($action==='analyze_all') {
                    $result=$seo->analyzeAll($actorId,1000);
                    $seo->propose($actorId);
                    $message=$result['analyzed'].' صفحه در همین سایت تحلیل شد؛ '.
                        $result['new_notices'].' اخطار جدید ثبت شد.';
                }elseif($action==='advisory_status'){
                    $seo->setAdvisoryStatus(
                        $actorId,(int)($_POST['id']??0),
                        (string)($_POST['status']??'')
                    );
                    $message='وضعیت اخطار ذخیره شد.';
                }elseif($action==='suggestion_status'){
                    $seo->setSuggestionStatus(
                        $actorId,(int)($_POST['id']??0),
                        (string)($_POST['status']??'')
                    );
                    $message='وضعیت پیشنهاد ذخیره شد.';
                }elseif($action==='save_cadence'){
                    $seo->saveCadence($actorId,(int)($_POST['cadence_days']??3));
                    $seo->propose($actorId);
                    $message='برنامه پیشنهادی انتشار سایت ذخیره شد.';
                }else{
                    throw new \RuntimeException('tenant_seo_action_invalid');
                }
                Audit::log('seo.tenant_scoped_action',
                    'عملیات سئو در سایت جاری اجرا شد',$actorId,
                    ['site_id'=>SiteContext::id(),'action'=>$action]);
            }catch(Throwable $e){
                $code=$e->getMessage();
                http_response_code(
                    $code==='tenant_access_denied'?403:
                    ($code==='tenant_content_unavailable'?404:409)
                );
                $error='عملیات انجام نشد؛ مالکیت، مجوز یا ساختار داده‌های سایت تأیید نشد.';
            }
            // Re-read only the current site's result set after a mutation.
            $data=$seo->dashboard((int)$user['id']);
        }

        $reports=$data['reports'];
        $advisories=$data['advisories'];
        $suggestions=$data['suggestions'];
        $cadence=$data['cadence'];
        $summary=$data['summary'];
        View::render('seo',compact(
            'user','reports','advisories','suggestions','cadence','summary',
            'error','message'
        ));
    }
}
