<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\{Audit, Auth, Database, DeliveryPolicy, PublishingCrypto, Security, View};

final class PublishingController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner', 'admin']);
        $pdo = Database::connection();
        $error = null;
        $externalDeliveryEnabled = DeliveryPolicy::externalEnabled();
        $telegramNetworkEnabled = DeliveryPolicy::telegramEnabled();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $pageId = (int)($_POST['page_id'] ?? 0);
                $at = trim((string)($_POST['scheduled_at'] ?? ''));
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['destination_ids'] ?? [])))));
                if ($pageId < 1 || !$ids || strtotime($at) === false) {
                    throw new \InvalidArgumentException('صفحه، زمان و حداقل یک مقصد لازم است.');
                }

                DeliveryPolicy::assertExternalEnabled();
                $statement = $pdo->prepare('SELECT COUNT(*) FROM cms_pages WHERE id=:id');
                $statement->execute(['id'=>$pageId]);
                if ((int)$statement->fetchColumn() !== 1) {
                    throw new \InvalidArgumentException('صفحه انتخاب‌شده وجود ندارد.');
                }

                $destinationRows = $pdo->query(
                    'SELECT id,provider FROM social_destinations WHERE is_enabled=TRUE AND id IN (' . implode(',', $ids) . ')'
                )->fetchAll();
                if (count($destinationRows) !== count($ids)) {
                    throw new \InvalidArgumentException('یکی از مقصدهای انتخاب‌شده فعال یا معتبر نیست.');
                }
                foreach ($destinationRows as $destination) {
                    DeliveryPolicy::assertProviderEnabled((string)$destination['provider']);
                }

                $statement = $pdo->prepare(
                    'INSERT INTO publication_jobs(page_id,scheduled_at,next_attempt_at,destination_ids,created_by) '
                    . 'VALUES(:page,:at,:at2,:destinations,:user)'
                );
                $scheduledAt = date('Y-m-d H:i:s', strtotime($at));
                $statement->execute([
                    'page'=>$pageId,
                    'at'=>$scheduledAt,
                    'at2'=>$scheduledAt,
                    'destinations'=>json_encode($ids),
                    'user'=>$user['id'],
                ]);
                Audit::log('publishing.scheduled', 'انتشار محتوا زمان‌بندی شد', (int)$user['id'], [
                    'page_id'=>$pageId,
                    'destinations'=>$ids,
                ]);
                header('Location: /admin/publishing?saved=1');
                return;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $pages = $pdo->query("SELECT id,title,locale,status FROM cms_pages WHERE status='published' ORDER BY id DESC")->fetchAll();
        $destinations = $pdo->query('SELECT id,name,provider FROM social_destinations WHERE is_enabled=TRUE ORDER BY name')->fetchAll();
        $jobs = $pdo->query('SELECT j.*,p.title,p.locale FROM publication_jobs j JOIN cms_pages p ON p.id=j.page_id ORDER BY j.id DESC LIMIT 100')->fetchAll();
        View::render('publishing', compact(
            'user', 'pages', 'destinations', 'jobs', 'error', 'externalDeliveryEnabled', 'telegramNetworkEnabled'
        ));
    }

    public function destinations(): void
    {
        $user = Auth::requireUser(['owner']);
        $pdo = Database::connection();
        $error = null;
        $externalDeliveryEnabled = DeliveryPolicy::externalEnabled();
        $telegramNetworkEnabled = DeliveryPolicy::telegramEnabled();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $action = (string)($_POST['action'] ?? 'save');
                $id = (int)($_POST['id'] ?? 0);
                if ($action === 'delete') {
                    $pdo->prepare('DELETE FROM social_destinations WHERE id=:id')->execute(['id'=>$id]);
                } else {
                    $name = trim((string)($_POST['name'] ?? ''));
                    $provider = (string)($_POST['provider'] ?? '');
                    $endpoint = trim((string)($_POST['endpoint'] ?? ''));
                    $account = trim((string)($_POST['account_ref'] ?? ''));
                    $token = trim((string)($_POST['token'] ?? ''));
                    if (mb_strlen($name) < 2 || !in_array($provider, ['telegram', 'vk', 'x', 'webhook'], true) || $token === '') {
                        throw new \InvalidArgumentException('نام، Provider و Token الزامی است.');
                    }
                    if ($provider === 'webhook' && !self::safeUrl($endpoint)) {
                        throw new \InvalidArgumentException('Webhook باید HTTPS عمومی باشد.');
                    }
                    $pdo->prepare(
                        'INSERT INTO social_destinations(name,provider,endpoint,account_ref,encrypted_token) '
                        . 'VALUES(:name,:provider,:endpoint,:account,:token)'
                    )->execute([
                        'name'=>$name,
                        'provider'=>$provider,
                        'endpoint'=>$endpoint ?: null,
                        'account'=>$account ?: null,
                        'token'=>PublishingCrypto::encrypt($token),
                    ]);
                }
                Audit::log('publishing.destination_changed', 'مقصد انتشار تغییر کرد', (int)$user['id']);
                header('Location: /admin/social-destinations');
                return;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $destinations = $pdo->query(
            'SELECT id,name,provider,endpoint,account_ref,is_enabled,created_at FROM social_destinations ORDER BY id DESC'
        )->fetchAll();
        View::render('social-destinations', compact(
            'user', 'destinations', 'error', 'externalDeliveryEnabled', 'telegramNetworkEnabled'
        ));
    }

    private static function safeUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        foreach (gethostbynamel($host) ?: [] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return $host !== '';
    }
}
