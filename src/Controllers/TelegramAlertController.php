<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Access,Audit,Database,DeliveryPolicy,Security,TelegramSyncService,TravelAlertService,TravelAlertWorker,View};

final class TelegramAlertController
{
    public function index(): void
    {
        $user = Access::require('telegram');
        if (!in_array((string)($user['role'] ?? ''), ['owner','admin'], true)) {
            http_response_code(403);
            View::render('error', ['title'=>'دسترسی غیرمجاز','message'=>'فعال‌سازی هشدار Telegram فقط برای مالک یا مدیر مجاز است.']);
            return;
        }

        $message = null;
        $error = null;
        $optInUrl = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                Security::verifyCsrf();
                $action = (string)($_POST['action'] ?? '');
                if ($action === 'manager_optin') {
                    $result = TravelAlertService::managerOptIn((int)($_POST['connection_id'] ?? 0), (int)$user['id'], (string)($_POST['locale'] ?? 'fa'));
                    if (($result['status'] ?? '') === 'pending' && isset($result['url'])) {
                        $optInUrl = (string)$result['url'];
                        $message = 'پیوند یک‌بارمصرف رضایت ساخته شد؛ خود مدیر باید آن را باز کند و ربات را Start کند.';
                    } elseif (($result['status'] ?? '') === 'opted_in') {
                        $message = 'این مدیر پیش‌تر برای همین اتصال، هشدارها را فعال کرده است.';
                    } else {
                        $message = 'هشدارها هنوز با feature gate فعال نشده‌اند؛ هیچ پیوندی ساخته نشد.';
                    }
                    Audit::log('travel_alert.manager_optin_requested','درخواست رضایت مدیر برای هشدار Telegram ثبت شد',(int)$user['id'],['connection_id'=>(int)($_POST['connection_id'] ?? 0)]);
                } elseif ($action === 'subscription_status') {
                    $status = (string)($_POST['status'] ?? '');
                    TravelAlertService::setSubscriptionStatus((int)($_POST['subscription_id'] ?? 0), $status);
                    $message = $status === 'revoked' ? 'اشتراک لغو و کارهای در انتظار آن متوقف شد.' : 'اشتراک موقتاً متوقف شد.';
                    Audit::log('travel_alert.subscription_updated','وضعیت اشتراک هشدار تغییر کرد',(int)$user['id'],['subscription_id'=>(int)($_POST['subscription_id'] ?? 0),'status'=>$status]);
                } elseif ($action === 'process_queue') {
                    DeliveryPolicy::assertTelegramAlertsEnabled();
                    $result = TravelAlertWorker::process(20);
                    $message = (int)$result['sent'].' پیام ارسال، '.(int)$result['failed'].' ناموفق و '.(int)$result['cancelled'].' پیام لغو شد.';
                } else {
                    throw new \RuntimeException('عملیات هشدار Telegram معتبر نیست.');
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $pdo = Database::connection();
        $connections = $pdo->query(
            "SELECT id,name,bot_username,locale,is_enabled,webhook_status FROM telegram_connections ORDER BY id DESC"
        )->fetchAll();
        $subscriptions = $pdo->query(
            'SELECT s.id,s.recipient_type,s.source_type,s.source_id,s.locale,s.status,s.consented_at,s.revoked_at,s.created_at,'
            . 's.telegram_chat_id,c.name AS connection_name,c.bot_username FROM telegram_alert_subscriptions s '
            . 'JOIN telegram_connections c ON c.id=s.connection_id ORDER BY s.updated_at DESC,s.id DESC LIMIT 150'
        )->fetchAll();
        foreach ($subscriptions as &$subscription) {
            $chat = (string)($subscription['telegram_chat_id'] ?? '');
            $subscription['chat_display'] = $chat === '' ? '—' : '•••' . substr($chat, -4);
        }
        unset($subscription);
        $outbox = $pdo->query(
            'SELECT o.id,o.event_type,o.status,o.attempts,o.max_attempts,o.next_attempt_at,o.last_error,o.created_at,o.updated_at,'
            . 'c.name AS connection_name,s.recipient_type,s.source_type,s.source_id FROM telegram_alert_outbox o '
            . 'JOIN telegram_connections c ON c.id=o.connection_id JOIN telegram_alert_subscriptions s ON s.id=o.subscription_id '
            . 'ORDER BY o.id DESC LIMIT 150'
        )->fetchAll();
        $alertsEnabled = DeliveryPolicy::telegramAlertsEnabled();
        $deliveryGates = [
            ['label'=>'اجازهٔ ارسال خارجی','key'=>'EXTERNAL_DELIVERY_ENABLED','enabled'=>DeliveryPolicy::externalEnabled()],
            ['label'=>'شبکهٔ Telegram','key'=>'TELEGRAM_NETWORK_ENABLED','enabled'=>DeliveryPolicy::telegramNetworkGateEnabled()],
            ['label'=>'هشدارهای Travel و Visa','key'=>'TELEGRAM_ALERT_DELIVERY_ENABLED','enabled'=>DeliveryPolicy::telegramAlertsGateEnabled()],
        ];
        $connectionReadiness = [];
        foreach ($connections as $connection) {
            $username = trim((string)($connection['bot_username'] ?? ''));
            $webhookReady = (int)($connection['is_enabled'] ?? 0) === 1
                && (string)($connection['webhook_status'] ?? '') === 'active';
            $connectionReadiness[] = [
                'name'=>(string)($connection['name'] ?? ''),
                'username'=>$username,
                'webhook_ready'=>$webhookReady,
                'ready'=>$webhookReady && $username !== '',
            ];
        }
        $alertPreview = [
            [
                'recipient'=>'مدیر آژانس',
                'event'=>'ثبت یا تکمیل پروندهٔ ویزا',
                'message'=>'پروندهٔ ویزای VZ•••••• برای بررسی آماده است. برای مشاهده، وارد پنل امن شوید.',
            ],
            [
                'recipient'=>'کاربرِ رضایت‌داده',
                'event'=>'تغییر وضعیت یا بررسی مدرک',
                'message'=>'برای پروندهٔ ویزای VZ•••••• یک به‌روزرسانی ثبت شد. برای مشاهده، وارد صفحهٔ امن پرونده شوید.',
            ],
        ];
        View::render('travel-alerts', compact(
            'user','connections','subscriptions','outbox','alertsEnabled','deliveryGates','connectionReadiness','alertPreview','message','error','optInUrl'
        ));
    }
}
