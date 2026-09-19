<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir() . '/vazincms-stage4-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('VAZINCMS_STORAGE_PATH=' . $runtime);
putenv('SESSION_SECURE=false');
putenv('APP_KEY=stage4-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');
putenv('APP_URL=https://stage4.example.test');
putenv('VAZIN_ID_ALLOWED_HOSTS=id.vazin.online');
putenv('EXTERNAL_DELIVERY_ENABLED=true');
putenv('TELEGRAM_NETWORK_ENABLED=true');
require $root . '/src/bootstrap.php';

use VazinCMS\{
    ContentAdvisoryService,
    Database,
    EditorialPlanner,
    ExtensionManager,
    ExtensionRuntime,
    Security,
    SecretStore,
    TelegramBotApi,
    TelegramHistoryImporter,
    TelegramSyncService,
    TelegramWebAppAuth,
    VazinIdSettings
};
use VazinCMS\Controllers\{ContentController,SeoController};

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$cleanup = static function (string $path) use (&$cleanup): void {
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) $cleanup($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($path);
};

try {
    $pdo = Database::connection();
    $pdo->exec((string)file_get_contents($root . '/database/schema-sqlite.sql'));
    $migrations = glob($root . '/database/migrations/*-sqlite.sql') ?: [];
    usort($migrations, static fn(string $left, string $right): int => version_compare(
        explode('-', basename($left))[0],
        explode('-', basename($right))[0]
    ));
    foreach ($migrations as $migration) {
        $version = explode('-', basename($migration))[0];
        $seen = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');
        $seen->execute(['version' => $version]);
        if (!$seen->fetchColumn()) $pdo->exec((string)file_get_contents($migration));
    }
    $check((bool)$pdo->query("SELECT 1 FROM schema_migrations WHERE version='10.9.0'")->fetchColumn(), '10.9.0 migration was not applied');

    $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(:name,:email,:password,'owner','active')")
        ->execute(['name'=>'Stage 4 Owner','email'=>'stage4@example.test','password'=>password_hash('contract-only', PASSWORD_DEFAULT)]);
    $ownerId = (int)$pdo->lastInsertId();

    $sealed = SecretStore::seal('telegram-secret', 'stage4.contract');
    $check($sealed !== 'telegram-secret' && SecretStore::open($sealed, 'stage4.contract') === 'telegram-secret', 'secret store round trip failed');
    $contextRejected = false;
    try { SecretStore::open($sealed, 'stage4.other'); } catch (Throwable) { $contextRejected = true; }
    $check($contextRejected, 'secret store did not bind ciphertext to its context');

    $botToken = '123456789:' . str_repeat('A', 35);
    $api = new TelegramBotApi($botToken, static fn(string $method, array $parameters): array => [
        'ok'=>true,
        'result'=>['id'=>1,'method'=>$method,'parameters'=>$parameters],
    ]);
    $check(($api->call('getMe')['method'] ?? '') === 'getMe', 'Telegram API transport contract failed');
    $methodRejected = false;
    try { $api->call('unsupportedMethod'); } catch (Throwable) { $methodRejected = true; }
    $check($methodRejected, 'Telegram API method allowlist failed');

    $userJson = json_encode(['id'=>99887766,'first_name'=>'Contract'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $authValues = ['auth_date'=>(string)time(),'query_id'=>'AAE-contract','user'=>$userJson];
    ksort($authValues, SORT_STRING);
    $checkString = implode("\n", array_map(
        static fn(string $key, string $value): string => $key . '=' . $value,
        array_keys($authValues),
        array_values($authValues)
    ));
    $webAppSecret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $authValues['hash'] = hash_hmac('sha256', $checkString, $webAppSecret);
    $initData = http_build_query($authValues, '', '&', PHP_QUERY_RFC3986);
    $validated = TelegramWebAppAuth::validate($initData, $botToken);
    $check((string)$validated['user']['id'] === '99887766', 'Telegram Web App signature validation failed');
    $tamperRejected = false;
    try { TelegramWebAppAuth::validate(str_replace('AAE-contract', 'AAE-tampered', $initData), $botToken); } catch (Throwable) { $tamperRejected = true; }
    $check($tamperRejected, 'tampered Telegram Web App data was accepted');

    $connectionId = TelegramSyncService::createConnection([
        'name'=>'Contract Channel',
        'chat_id'=>'-1001234567890',
        'bot_token'=>$botToken,
        'sync_mode'=>'bidirectional',
        'incoming_status'=>'published',
        'locale'=>'fa',
        'auto_publish_site'=>1,
        'web_app_enabled'=>1,
        'is_enabled'=>1,
    ], $ownerId);
    $connection = TelegramSyncService::connectionById($connectionId);
    $check(!str_contains((string)$connection['encrypted_bot_token'], $botToken), 'Telegram token was stored in plaintext');
    $check(TelegramSyncService::botToken($connection) === $botToken, 'Telegram token could not be decrypted');
    $check(preg_match('/^[a-f0-9]{64}$/', TelegramSyncService::webhookSecret($connection)) === 1, 'Telegram webhook secret is invalid');
    TelegramSyncService::saveAdminMapping($connectionId, '99887766', $ownerId);
    $command = TelegramSyncService::receiveWebhook($connection, [
        'update_id'=>1,
        'message'=>['message_id'=>1,'from'=>['id'=>99887766],'chat'=>['id'=>-1001234567890],'text'=>'/status'],
    ]);
    $check($command['status'] === 'queued', 'mapped Telegram manager command was not accepted');
    $unauthorizedCommand = TelegramSyncService::receiveWebhook($connection, [
        'update_id'=>2,
        'message'=>['message_id'=>2,'from'=>['id'=>11223344],'chat'=>['id'=>-1001234567890],'text'=>'/warnings'],
    ]);
    $check($unauthorizedCommand['status'] === 'ignored' && $unauthorizedCommand['reason'] === 'unauthorized_manager', 'unmapped group member could run manager commands');

    ExtensionManager::reset();
    ExtensionRuntime::reset();
    $originalTimestamp = 1577934245;
    $firstSync = TelegramSyncService::syncChannelMessage($connection, [
        'message_id'=>10,
        'date'=>$originalTimestamp,
        'text'=>'نخستین پیام آرشیوی کانال',
    ]);
    $check($firstSync['created'] === true, 'incoming Telegram post was not created');
    $pageId = (int)$firstSync['page_id'];
    $pageQuery = $pdo->prepare('SELECT * FROM cms_pages WHERE id=:id');
    $pageQuery->execute(['id'=>$pageId]);
    $telegramPage = $pageQuery->fetch();
    $expectedDate = gmdate('Y-m-d H:i:s', $originalTimestamp);
    $check(is_array($telegramPage) && $telegramPage['content_type'] === 'post' && $telegramPage['published_at'] === $expectedDate, 'original Telegram date was not preserved');
    $check((int)$telegramPage['is_home'] === 0 && $telegramPage['source_provider'] === 'telegram', 'Telegram post leaked into home-page semantics');

    $editedSync = TelegramSyncService::syncChannelMessage($connection, [
        'message_id'=>10,
        'date'=>$originalTimestamp,
        'edit_date'=>$originalTimestamp + 3600,
        'text'=>'نسخهٔ ویرایش‌شدهٔ پیام آرشیوی کانال',
    ]);
    $check($editedSync['created'] === false && (int)$editedSync['page_id'] === $pageId, 'Telegram edit created a duplicate site post');
    $bodyQuery = $pdo->prepare('SELECT body FROM cms_pages WHERE id=:id');
    $bodyQuery->execute(['id'=>$pageId]);
    $check(str_contains((string)$bodyQuery->fetchColumn(), 'ویرایش‌شده'), 'Telegram edit did not update the site post');

    $history = [];
    for ($index = 1; $index <= 75; $index++) {
        $history[] = ['id'=>1000+$index,'date'=>$originalTimestamp+$index,'message'=>'پیام تاریخچه شماره ' . $index];
    }
    $fingerprint = hash('sha256', 'stage4-history-contract');
    $import = TelegramHistoryImporter::importMessages($connectionId, $history, 'mtproto', $fingerprint);
    $check($import['scanned'] === 75 && $import['created'] === 75 && $import['errors'] === 0, 'full-history import stopped early or failed');
    $reimport = TelegramHistoryImporter::importMessages($connectionId, $history, 'mtproto', $fingerprint);
    $check($reimport['scanned'] === 75 && $reimport['created'] === 0 && $reimport['updated'] === 75, 'history re-sync was not idempotent');
    $telegramPostCount = (int)$pdo->query("SELECT COUNT(*) FROM cms_pages WHERE source_provider='telegram'")->fetchColumn();
    $check($telegramPostCount === 76, 'history sync created missing or duplicate posts');
    $check(TelegramSyncService::queuePage($pageId) === 0, 'incoming Telegram page was echoed back to Telegram');

    $sessionVersion = (int)$pdo->query('SELECT session_version FROM users WHERE id=' . $ownerId)->fetchColumn();
    $_SESSION['user_id'] = $ownerId;
    $_SESSION['session_version'] = $sessionVersion;
    $_SESSION['authenticated_at'] = $_SESSION['last_activity_at'] = $_SESSION['session_touched_at'] = time();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO active_sessions(user_id,session_hash) VALUES(:user,:hash)')
        ->execute(['user'=>$ownerId,'hash'=>hash('sha256',session_id())]);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        '_csrf'=>Security::csrf(),'slug'=>'editor-contract','locale'=>'fa','title'=>'نوشتهٔ ساخته‌شده در ویرایشگر',
        'body'=>'متن کافی برای آزمون ذخیره‌سازی نوشته و اجرای hookهای ماژولار مدیریت محتوا.',
        'status'=>'published','content_type'=>'post','published_at'=>'2021-04-05T12:30',
        'meta_title'=>'عنوان آزمون SEO','meta_description'=>'توضیح آزمون SEO','canonical_url'=>'https://example.test/fa/page/editor-contract',
        'featured_image'=>'https://example.test/image.webp','og_title'=>'عنوان اشتراک‌گذاری','og_description'=>'توضیح اشتراک‌گذاری',
        'schema_type'=>'BlogPosting','robots_index'=>'1','robots_follow'=>'1',
    ];
    (new ContentController())->pages();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $editorQuery = $pdo->query("SELECT * FROM cms_pages WHERE slug='editor-contract' AND locale='fa'");
    $editorPage = $editorQuery->fetch();
    $check(is_array($editorPage) && $editorPage['content_type'] === 'post' && $editorPage['published_at'] === '2021-04-05 12:30:00', 'content editor did not preserve post type/date');
    $check($editorPage['og_title'] === 'عنوان اشتراک‌گذاری' && $editorPage['schema_type'] === 'BlogPosting', 'content editor did not save SEO sharing fields');
    $check((int)$pdo->query('SELECT COUNT(*) FROM seo_reports WHERE page_id=' . (int)$editorPage['id'])->fetchColumn() === 1, 'content-saved SEO hook did not run');
    $check((int)$pdo->query('SELECT COUNT(*) FROM telegram_outbox WHERE cms_page_id=' . (int)$editorPage['id'])->fetchColumn() === 1, 'content-saved Telegram hook did not run');

    $siteInsert = $pdo->prepare(
        "INSERT INTO cms_pages(slug,locale,title,body,status,is_home,author_id,meta_title,meta_description,canonical_url,featured_image,content_type,published_at,source_provider,source_ref,robots_index,robots_follow,og_title,og_description,schema_type) "
        . "VALUES('contract-health','fa','درمان قطعی آزمایشی','این متن ادعای درمان قطعی و معجزه دارد و فقط برای آزمون اخطار مدیریتی است.','published',0,:author,'','','','', 'post',CURRENT_TIMESTAMP,'','',1,1,'','','Article')"
    );
    $siteInsert->execute(['author'=>$ownerId]);
    $sitePageId = (int)$pdo->lastInsertId();
    $report = ContentAdvisoryService::analyzePage($sitePageId, false);
    $check($report['score'] < 100 && ContentAdvisoryService::openCount() > 0, 'SEO/content advisories were not generated');
    $statusQuery = $pdo->prepare('SELECT status FROM cms_pages WHERE id=:id');
    $statusQuery->execute(['id'=>$sitePageId]);
    $check($statusQuery->fetchColumn() === 'published', 'content advisory incorrectly blocked publication');
    $check(TelegramSyncService::queuePage($sitePageId) === 1, 'site-to-Telegram outbox was not queued');
    $check((int)$pdo->query("SELECT COUNT(*) FROM telegram_outbox WHERE action='send_page' AND cms_page_id=" . $sitePageId)->fetchColumn() === 1, 'site post outbox row is missing');

    EditorialPlanner::saveCadence(5);
    $suggestion = EditorialPlanner::refresh();
    $check($suggestion['cadence_days'] === 5, 'editorial cadence was not preserved');
    $check((int)$pdo->query("SELECT COUNT(*) FROM editorial_suggestions WHERE status='open'")->fetchColumn() >= 1, 'editorial suggestion was not created');

    VazinIdSettings::save([
        'issuer_url'=>'https://id.vazin.online',
        'client_id'=>'stage4-client',
        'scopes'=>'openid profile email forbidden',
        'is_enabled'=>1,
        'auto_provision'=>1,
    ], $ownerId);
    $identity = VazinIdSettings::current();
    $check($identity['source'] === 'database' && $identity['client_id'] === 'stage4-client' && $identity['scopes'] === 'openid profile email', 'Vazin ID module settings failed');
    $check(VazinIdSettings::configured(), 'Vazin ID module did not report a valid configuration');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    (new SeoController())->sitemap();
    $sitemap = (string)ob_get_clean();
    $check(str_contains($sitemap, '<urlset') && str_contains($sitemap, 'https://stage4.example.test/fa/page/editor-contract'), 'SEO sitemap output failed');
    ob_start();
    (new SeoController())->robots();
    $robots = (string)ob_get_clean();
    $check(str_contains($robots, 'Sitemap: https://stage4.example.test/sitemap.xml'), 'SEO robots output failed');

    echo "Stage 4 contract: OK\n";
} finally {
    $cleanup($runtime);
}
