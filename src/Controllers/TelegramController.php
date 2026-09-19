<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\{Access,Audit,Auth,Database,DeliveryPolicy,Security,TelegramHistoryImporter,TelegramSyncService,TelegramWebAppAuth,View};
use Throwable;

final class TelegramController
{
    public function index(): void
    {
        $user = Access::require('telegram');
        $error = null; $message = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $action = (string)($_POST['action'] ?? 'create');
                if ($action === 'create') {
                    $id = TelegramSyncService::createConnection($_POST,(int)$user['id']);
                    Audit::log('telegram.connection_created','اتصال Telegram ساخته شد',(int)$user['id'],['connection_id'=>$id]);
                    $message = 'اتصال با وضعیت غیرفعال ذخیره شد. واردسازی تاریخچه بدون فعال‌سازی شبکه در دسترس است.';
                } elseif ($action === 'update') {
                    TelegramSyncService::updateConnection((int)($_POST['connection_id']??0),$_POST);
                    $message = 'تنظیمات اتصال به‌روزرسانی شد.';
                } elseif ($action === 'register') {
                    DeliveryPolicy::assertTelegramEnabled();
                    $identity = TelegramSyncService::registerWebhook((int)($_POST['connection_id']??0));
                    $message = 'Webhook ربات @'.($identity['username']??'telegram').' ثبت شد.';
                    Audit::log('telegram.webhook_registered','Webhook Telegram ثبت شد',(int)$user['id'],['connection_id'=>(int)($_POST['connection_id']??0)]);
                } elseif ($action === 'map_admin') {
                    TelegramSyncService::saveAdminMapping((int)($_POST['connection_id']??0),trim((string)($_POST['telegram_user_id']??'')),(int)($_POST['cms_user_id']??0));
                    $message = 'دسترسی مدیر برای Telegram Web App ثبت شد.';
                } elseif ($action === 'import') {
                    $file = $_FILES['history_json']??null;
                    if (!is_array($file) || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new \RuntimeException('فایل JSON تاریخچه دریافت نشد.');
                    $result = TelegramHistoryImporter::importFile((int)($_POST['connection_id']??0),(string)$file['tmp_name'],(string)($_POST['source_type']??'telegram_export'));
                    $message = $result['scanned'].' پیام بررسی شد؛ '.$result['created'].' پست ساخته و '.$result['updated'].' پست همگام شد.';
                    Audit::log('telegram.history_imported','تاریخچه Telegram وارد شد',(int)$user['id'],$result);
                } elseif ($action === 'test_notice') {
                    DeliveryPolicy::assertTelegramEnabled();
                    $connection = TelegramSyncService::connectionById((int)($_POST['connection_id']??0));
                    if ((string)$connection['manager_chat_id']==='') throw new \RuntimeException('ابتدا شناسهٔ گفت‌وگوی مدیر را ثبت کنید.');
                    if (!TelegramSyncService::queueManagerNotice((int)$connection['id'],(string)$connection['manager_chat_id'],'پیام آزمایشی Vazin CMS با موفقیت وارد صف شد.','test-'.gmdate('YmdHi'))) throw new \RuntimeException('اتصال فعال نیست و پیام آزمایشی وارد صف نشد.');
                    $message = 'پیام آزمایشی وارد صف شد.';
                } elseif ($action === 'process_queue') {
                    DeliveryPolicy::assertTelegramEnabled();
                    $result = TelegramSyncService::processOutbox(20);
                    $message = $result['sent'].' پیام ارسال و '.$result['failed'].' پیام ناموفق شد.';
                } else throw new \RuntimeException('عملیات Telegram معتبر نیست.');
            } catch (Throwable $exception) { $error = $exception->getMessage(); }
        }
        $pdo = Database::connection();
        $connections = TelegramSyncService::connections();
        $admins = $pdo->query('SELECT a.*,u.name,u.email,c.name connection_name FROM telegram_admins a JOIN users u ON u.id=a.cms_user_id JOIN telegram_connections c ON c.id=a.connection_id ORDER BY a.id DESC')->fetchAll();
        $users = $pdo->query("SELECT id,name,email,role FROM users WHERE status='active' AND role IN ('owner','admin') ORDER BY role,name")->fetchAll();
        $messages = $pdo->query('SELECT m.*,c.name connection_name,p.title page_title FROM telegram_messages m JOIN telegram_connections c ON c.id=m.connection_id LEFT JOIN cms_pages p ON p.id=m.cms_page_id ORDER BY m.id DESC LIMIT 100')->fetchAll();
        $outbox = $pdo->query('SELECT o.*,c.name connection_name,p.title page_title FROM telegram_outbox o JOIN telegram_connections c ON c.id=o.connection_id LEFT JOIN cms_pages p ON p.id=o.cms_page_id ORDER BY o.id DESC LIMIT 100')->fetchAll();
        $imports = $pdo->query('SELECT i.*,c.name connection_name FROM telegram_history_imports i JOIN telegram_connections c ON c.id=i.connection_id ORDER BY i.id DESC LIMIT 50')->fetchAll();
        $appUrl = rtrim((string)getenv('APP_URL'),'/');
        $externalDeliveryEnabled = DeliveryPolicy::externalEnabled();
        $telegramNetworkEnabled = DeliveryPolicy::telegramEnabled();
        View::render('telegram',compact(
            'user','connections','admins','users','messages','outbox','imports','appUrl','error','message',
            'externalDeliveryEnabled','telegramNetworkEnabled'
        ));
    }

    public function webhook(string $key): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD']??'GET') !== 'POST') { http_response_code(405); echo '{"ok":false}'; return; }
        if (!DeliveryPolicy::telegramEnabled()) { http_response_code(503); echo '{"ok":false,"code":"telegram_network_disabled"}'; return; }
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE']??''), 2)[0]));
        if ($contentType !== 'application/json') { http_response_code(415); echo '{"ok":false,"code":"json_required"}'; return; }
        try {
            $connection = TelegramSyncService::connectionByWebhookKey($key);
            $provided = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
            if ($provided === '' || !hash_equals(TelegramSyncService::webhookSecret($connection),$provided)) { http_response_code(401); echo '{"ok":false}'; return; }
            $length = (int)($_SERVER['CONTENT_LENGTH']??0);
            if ($length > 2_000_000) throw new \RuntimeException('Webhook بیش از حد بزرگ است.');
            $raw = file_get_contents('php://input', false, null, 0, 2_000_001);
            if (!is_string($raw) || strlen($raw) > 2_000_000) throw new \RuntimeException('Webhook بیش از حد بزرگ است.');
            $update = is_string($raw)?json_decode($raw,true,128,JSON_BIGINT_AS_STRING):null;
            if (!is_array($update)) throw new \RuntimeException('JSON Webhook معتبر نیست.');
            $result = TelegramSyncService::receiveWebhook($connection,$update);
            echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            $correlation = bin2hex(random_bytes(8));
            error_log('[VazinCMS telegram webhook correlation='.$correlation.'] '.mb_substr($error->getMessage(),0,500));
            http_response_code(500);
            echo json_encode(['ok'=>false,'code'=>'webhook_failed','correlation'=>$correlation],JSON_UNESCAPED_SLASHES);
        }
    }

    public function publicChannel(string $locale): void
    {
        $pdo=Database::connection();$page=max(1,(int)($_GET['page']??1));$limit=20;$offset=($page-1)*$limit;
        $countQuery=$pdo->prepare("SELECT COUNT(*) FROM cms_pages WHERE source_provider='telegram' AND content_type='post' AND status='published' AND locale=:locale");$countQuery->execute(['locale'=>$locale]);$count=(int)$countQuery->fetchColumn();
        $query=$pdo->prepare("SELECT * FROM cms_pages WHERE source_provider='telegram' AND content_type='post' AND status='published' AND locale=:locale ORDER BY COALESCE(published_at,created_at) DESC,id DESC LIMIT $limit OFFSET $offset");
        $query->execute(['locale'=>$locale]);$posts=$query->fetchAll();$settings=[];
        foreach($pdo->query('SELECT setting_key,setting_value FROM cms_settings')->fetchAll() as$row)$settings[$row['setting_key']]=$row['setting_value'];
        $menuQuery=$pdo->prepare('SELECT label,url FROM cms_menu_items WHERE locale=:locale AND is_enabled=1 ORDER BY position,id');$menuQuery->execute(['locale'=>$locale]);$menu=$menuQuery->fetchAll();
        $meta = match ($locale) {
            'ru' => ['title'=>'Архив канала','description'=>'Материалы из Telegram с исходными датами публикации.'],
            'en' => ['title'=>'Channel archive','description'=>'Telegram posts with their original publication dates.'],
            default => ['title'=>'آرشیو کانال','description'=>'مطالب منتشرشده در کانال Telegram'],
        };
        View::renderPublic('telegram-channel',['locale'=>$locale,'posts'=>$posts,'page'=>$page,'pages'=>max(1,(int)ceil($count/$limit)),'settings'=>$settings,'menu'=>$menu,'siteName'=>$settings['site_name']??'VazinCMS','meta'=>$meta]);
    }

    public function webApp(string $key): void
    {
        try {
            DeliveryPolicy::assertTelegramEnabled();
            $connection = TelegramSyncService::connectionByWebhookKey($key);
            if ((int)$connection['web_app_enabled'] !== 1) throw new \RuntimeException('Telegram Web App غیرفعال است.');
            View::renderStandalone('telegram-webapp',['connection'=>$connection,'locale'=>$connection['locale']]);
        } catch (Throwable) { http_response_code(404); View::renderStandalone('telegram-webapp',['connection'=>null,'locale'=>'fa']); }
    }

    public function webAppSession(string $key): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD']??'GET') !== 'POST') { http_response_code(405); echo '{"ok":false}'; return; }
        try {
            DeliveryPolicy::assertTelegramEnabled();
            Security::verifyCsrf();
            $connection = TelegramSyncService::connectionByWebhookKey($key);
            if ((int)$connection['web_app_enabled'] !== 1) throw new \RuntimeException('Web App غیرفعال است.');
            $validated = TelegramWebAppAuth::validate((string)($_POST['init_data']??''),TelegramSyncService::botToken($connection));
            $telegramId = (string)$validated['user']['id'];
            $query = Database::connection()->prepare("SELECT u.id FROM telegram_admins a JOIN users u ON u.id=a.cms_user_id WHERE a.connection_id=:connection AND a.telegram_user_id=:telegram AND a.is_enabled=1 AND u.status='active' AND u.role IN ('owner','admin')");
            $query->execute(['connection'=>$connection['id'],'telegram'=>$telegramId]);
            $userId = (int)$query->fetchColumn();
            if ($userId < 1) throw new \RuntimeException('این حساب Telegram اجازهٔ مدیریت سایت را ندارد.');
            Auth::login($userId);
            Audit::log('telegram.webapp_login','ورود مدیر از Telegram Web App',$userId,['telegram_user_id'=>$telegramId,'connection_id'=>(int)$connection['id']]);
            echo '{"ok":true,"redirect":"/admin"}';
        } catch (Throwable $error) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }
}
