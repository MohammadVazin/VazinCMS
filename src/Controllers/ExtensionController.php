<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Audit,Auth,Database,ExtensionManager,Security,SiteContext,SiteScopeMode,SiteScopedExtensionPreferences,UpdateFeedService,View};

final class ExtensionController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner','admin']);
        if (SiteScopeMode::active(Database::connection())) {
            $this->sitePreferences($user);
            return;
        }
        if (($user['role']??'') !== 'owner') {
            http_response_code(403);
            View::render('error',[
                'title'=>'مدیریت افزونه‌ها',
                'message'=>'در نسخه تک‌سایتی فقط مدیر اصلی اجازه تغییر بسته‌ها را دارد.'
            ]);
            return;
        }
        $error = null;
        $message = isset($_GET['saved']) ? 'عملیات افزونه با موفقیت انجام شد.' : null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $action = (string) ($_POST['action'] ?? '');
            $key = strtolower(trim((string) ($_POST['extension_key'] ?? '')));
            try {
                if ($action === 'install') {
                    $row = ExtensionManager::installUploadedFile((array) ($_FILES['package'] ?? []));
                    $key = (string) $row['extension_key'];
                } elseif ($action === 'activate') {
                    ExtensionManager::activate($key);
                } elseif ($action === 'deactivate') {
                    ExtensionManager::deactivate($key);
                } elseif ($action === 'archive') {
                    ExtensionManager::archive($key);
                } elseif ($action === 'refresh_update_feed') {
                    UpdateFeedService::refresh(Database::connection(), true);
                } else {
                    throw new \InvalidArgumentException('عملیات افزونه معتبر نیست.');
                }
                Audit::log('cms.extension_' . $action, 'عملیات افزونه یا بررسی به‌روزرسانی انجام شد', (int) $user['id'], [
                    'extension_key' => $key,
                ]);
                header('Location: /admin/extensions?saved=1');
                return;
            } catch (Throwable $failure) {
                $error = $failure->getMessage();
            }
        }
        $extensions = ExtensionManager::all();
        $updateStatus = UpdateFeedService::refresh(Database::connection());
        View::render('extensions', compact('user', 'extensions', 'updateStatus', 'error', 'message'));
    }
    /**
     * Site-scoped requests are inert configuration choices. No global
     * extension/module/theme runtime is altered in this handler.
     */
    private function sitePreferences(array $user): void
    {
        $error=null;
        $message=isset($_GET['saved'])
            ? 'درخواست این سایت ثبت شد؛ اجرا و فعال‌سازی انجام نشده است.' : null;
        try {
            $prefs=new SiteScopedExtensionPreferences(
                Database::connection(),SiteContext::id()
            );
            $actorId=(int)$user['id'];
            $extensions=$prefs->catalog($actorId);
        } catch(Throwable $exception) {
            http_response_code(
                $exception->getMessage()==='tenant_access_denied' ? 403 : 409
            );
            View::render('error',[
                'title'=>'تنظیمات افزونه‌های سایت آماده نیست',
                'message'=>'ساختار جداگانه سایت یا مجوز مدیریت آن هنوز تأیید نشده است.'
            ]);
            return;
        }
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            Security::verifyCsrf();
            try {
                $action=(string)($_POST['action']??'');
                $key=(string)($_POST['extension_key']??'');
                if($action==='request_module') {
                    $desired=(string)($_POST['desired']??'');
                    if(!in_array($desired,['enabled','disabled'],true)) {
                        throw new \InvalidArgumentException('tenant_preference_invalid');
                    }
                    $prefs->requestModule($actorId,$key,$desired==='enabled');
                } elseif($action==='request_theme'){
                    $prefs->requestTheme($actorId,$key);
                } else {
                    throw new \InvalidArgumentException('tenant_preference_invalid');
                }
                Audit::log('cms.site_extension_preference',
                    'درخواست تغییر بسته برای سایت ثبت شد، بدون اعمال در runtime',
                    $actorId,[
                        'site_id'=>SiteContext::id(),
                        'action'=>$action,'extension_key'=>$key
                    ]);
                header('Location: /admin/extensions?saved=1');
                return;
            } catch(Throwable $exception) {
                $code=$exception->getMessage();
                http_response_code($code==='tenant_access_denied'?403:409);
                $error='درخواست ثبت نشد. مجوز، نوع بسته یا ساختار سایت معتبر نیست.';
            }
            $extensions=$prefs->catalog($actorId);
        }
        View::render('extensions-site-preferences',
            compact('user','extensions','error','message'));
    }

}
