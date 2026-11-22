<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use VazinCMS\Audit;
use VazinCMS\Auth;
use VazinCMS\Database;
use VazinCMS\DatabaseTransaction;
use VazinCMS\Security;
use VazinCMS\View;
use VazinCMS\SchemaHealth;
use VazinCMS\VazinIdClient;
use VazinCMS\VazinIdentityLink;
use VazinCMS\LocalStaffAuthenticator;
use VazinCMS\VazinIdSettings;
use VazinCMS\SiteContext;
use VazinCMS\SiteScopedOidcAdmission;
use VazinCMS\SiteScopedOidcAtomicLogin;
use VazinCMS\SiteScopedSessionPersistenceGuard;
use VazinCMS\SiteScopedRuntimePolicy;

final class AuthController
{
    public function login(): void
    {
        if (Auth::user() !== null) { header('Location: /admin'); return; }
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
            SchemaHealth::repairAuthentication();
            Security::verifyCsrf(); $email = strtolower(trim((string)($_POST['email'] ?? ''))); $password = (string)($_POST['password'] ?? '');
            $ip = Security::clientIp() ?: 'unknown'; $key = hash('sha256', $email . '|' . $ip); $pdo = Database::connection();
            $driver = (string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $limitSql = $driver === 'sqlite'
                ? "SELECT COUNT(*) FROM login_attempts WHERE attempt_key=:key AND succeeded=0 AND created_at > datetime('now','-15 minutes')"
                : "SELECT COUNT(*) FROM login_attempts WHERE attempt_key=:key AND succeeded=0 AND created_at > CURRENT_TIMESTAMP - INTERVAL '15 minutes'";
            $limit = $pdo->prepare($limitSql);
            $limit->execute(['key' => $key]);
            if ((int)$limit->fetchColumn() >= 5) { http_response_code(429); $error = 'تلاش‌های ناموفق زیاد است؛ ۱۵ دقیقه بعد دوباره امتحان کنید.'; }
            else {
                $user = LocalStaffAuthenticator::verify($pdo, $email, $password); $ok = $user !== null;
                $log = $pdo->prepare('INSERT INTO login_attempts(attempt_key,email,ip_address,succeeded) VALUES(:key,:email,:ip,:ok)'); $log->execute(['key' => $key, 'email' => $email, 'ip' => $ip, 'ok' => $ok ? 1 : 0]);
                if ($ok) { Auth::login((int)$user['id']); $isOwner = $user['role'] === 'owner'; Audit::log($isOwner ? 'auth.emergency_login' : 'auth.local_staff_login', $isOwner ? 'ورود اضطراری مدیر اصلی' : 'ورود محلی کارمند مجاز', (int)$user['id']); header('Location: /admin'); return; }
                Audit::log('auth.failed', 'تلاش ورود محلی ناموفق', null, ['email' => $email]); $error = 'ورود ناموفق است؛ اطلاعات حساب یا دسترسی خود را بررسی کنید.';
            }
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'درخواست منقضی یا نامعتبر است.') {
                    http_response_code(419);
                    $error = 'نشست ورود منقضی شده است. صفحه را تازه‌سازی و دوباره تلاش کنید.';
                } else {
                    error_log('[VazinCMS login] authentication runtime error: '.$e->getMessage());
                    http_response_code(503);
                    $error = 'سامانه ورود موقتاً در دسترس نیست. چند لحظه بعد دوباره تلاش کنید.';
                }
            } catch (\Throwable $e) {
                error_log('[VazinCMS login] authentication backend unavailable: '.$e->getMessage());
                http_response_code(503);
                $error = 'سامانه ورود موقتاً در دسترس نیست. اتصال دیتابیس را از مرکز مدیریت تعمیر و دوباره تلاش کنید.';
            }
        }
        View::render('login', compact('error'));
    }

    public function vazinIdStart(): void
    {
        $current=Auth::user();
        if($current!==null){
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Location: /admin');return;}
            Security::verifyCsrf();
            $pdo=Database::connection();
            if(SiteScopedRuntimePolicy::scopedMode($pdo)){
                try {
                    SiteScopedOidcAdmission::assertLinkingSession(
                        $pdo,SiteContext::id(),(int)$current['id'],
                        (string)($_SERVER['HTTP_HOST']??''),session_id(),
                        (string)($_SESSION['auth_provider']??''),
                        (int)($_SESSION['session_version']??0)
                    );
                }catch(\Throwable){
                    http_response_code(403);
                    View::render('error',[
                        'title'=>'مجوز اتصال هویت تأیید نشد',
                        'message'=>'برای اتصال Vazin ID، ورود معتبر به همین سایت لازم است.'
                    ]);
                    return;
                }
            }
            VazinIdClient::begin((string)($_POST['return_to']??'/admin'),(int)$current['id']);
        }
        VazinIdClient::begin((string)($_GET['return_to'] ?? '/admin'));
    }

    public function vazinIdCallback(): void
    {
        $result=VazinIdClient::callback();$p=$result['profile'];$pdo=Database::connection();
        try {
            $settings=VazinIdSettings::current();
            $linkUserId=isset($result['link_user_id'])
                ?(int)$result['link_user_id']:null;
            if(SiteScopedRuntimePolicy::scopedMode($pdo)){
                $siteId=SiteContext::id();
                $host=(string)($_SERVER['HTTP_HOST']??'');
                $sessionId=session_id();
                $sessionProvider=(string)($_SESSION['auth_provider']??'');
                $sessionVersion=(int)($_SESSION['session_version']??0);
                // Identity metadata and both rotated authentication ledgers
                // MUST either commit together or roll back together.
                $user=SiteScopedOidcAtomicLogin::complete(
                    $pdo,$p,$siteId,$linkUserId,$host,
                    $sessionId,$sessionProvider,$sessionVersion
                );
                // A successful database COMMIT is not enough: persist PHP
                // session storage before the success redirect. Failure
                // compensates by revoking both newly-created DB ledgers.
                SiteScopedSessionPersistenceGuard::persistOrRevoke(
                    $pdo,$siteId,(int)$user['id'],session_id()
                );
            }else{
                $user=DatabaseTransaction::immediate($pdo,
                    static fn():array=>VazinIdentityLink::resolve(
                        $pdo,$p,$linkUserId,(int)($settings['auto_provision']??1)===1
                    )
                );
                // Legacy single-site behavior remains unchanged; do not
                // force tenant-only admission upon installed customers.
                Auth::login((int)$user['id'],'vazin_id');
            }
            Audit::log('auth.vazin_id','ورود موفق با Vazin ID',(int)$user['id'],['subject'=>(string)$p['sub']]);
            header('Location: '.(string)$result['return_to'],true,303);
        } catch (\RuntimeException $e) {
            if(in_array($e->getMessage(),[
                'tenant_oidc_session_persistence_failed',
                'tenant_oidc_session_persistence_ambiguous'
            ],true)){
                http_response_code(503);
                header('Cache-Control: no-store');
                View::render('error',[
                    'title'=>'ورود هنوز تأیید نشده است',
                    'message'=>'نشست ورود ذخیره نشد؛ لطفاً دوباره وارد شوید.'
                ]);
                return;
            }
            if(in_array($e->getMessage(),[
                'tenant_oidc_staff_not_authorized',
                'tenant_oidc_link_session_denied',
                'tenant_session_scope_denied',
                'tenant_session_binding_denied',
                'tenant_session_active_record_required'
            ],true)) {
                http_response_code(403);
                View::render('error',[
                    'title'=>'ورود Vazin ID مجاز نیست',
                    'message'=>'برای ورود یا اتصال Vazin ID، حساب باید از قبل عضویت معتبر در همین سایت داشته باشد.'
                ]);
                return;
            }
            http_response_code(409);
            View::render('error',[
                'title'=>'اتصال Vazin ID انجام نشد',
                'message'=>$e->getMessage()
            ]);
        } catch (\Throwable $e) { throw $e; }
    }

    private function rollbackBestEffort(\PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable) {
        }
    }


    public function logout(): void
    {
        $user = Auth::requireUser(); Security::verifyCsrf(); Audit::log('auth.logout', 'خروج از سامانه', (int)$user['id']); Auth::logout(); header('Location: /login');
    }
}
