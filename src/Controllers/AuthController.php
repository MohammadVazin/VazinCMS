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
use VazinCMS\VazinIdSettings;

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
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email=:email AND status='active' AND role='owner'"); $stmt->execute(['email' => $email]); $user = $stmt->fetch(); $ok = $user && password_verify($password, $user['password_hash']);
                $log = $pdo->prepare('INSERT INTO login_attempts(attempt_key,email,ip_address,succeeded) VALUES(:key,:email,:ip,:ok)'); $log->execute(['key' => $key, 'email' => $email, 'ip' => $ip, 'ok' => $ok ? 1 : 0]);
                if ($ok) { Auth::login((int)$user['id']); Audit::log('auth.emergency_login', 'ورود اضطراری مدیر اصلی', (int)$user['id']); header('Location: /admin'); return; }
                Audit::log('auth.failed', 'تلاش ورود اضطراری ناموفق', null, ['email' => $email]); $error = 'ورود محلی فقط برای مدیر اصلی و شرایط اضطراری فعال است.';
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
            VazinIdClient::begin((string)($_POST['return_to']??'/admin'),(int)$current['id']);
        }
        VazinIdClient::begin((string)($_GET['return_to'] ?? '/admin'));
    }

    public function vazinIdCallback(): void
    {
        $result=VazinIdClient::callback();$p=$result['profile'];$pdo=Database::connection();
        try {
            $settings=VazinIdSettings::current();
            $user=DatabaseTransaction::immediate($pdo,static fn():array=>VazinIdentityLink::resolve($pdo,$p,isset($result['link_user_id'])?(int)$result['link_user_id']:null,(int)($settings['auto_provision']??1)===1));
            Auth::login((int)$user['id'], 'vazin_id');Audit::log('auth.vazin_id','ورود موفق با Vazin ID',(int)$user['id'],['subject'=>(string)$p['sub']]);
            header('Location: '.(string)$result['return_to'],true,303);
        } catch (\RuntimeException $e) {
            http_response_code(409);View::render('error',['title'=>'اتصال Vazin ID انجام نشد','message'=>$e->getMessage()]);
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
