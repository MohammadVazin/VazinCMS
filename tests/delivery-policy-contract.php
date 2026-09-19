<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir() . '/vazincms-delivery-policy-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('VAZINCMS_STORAGE_PATH=' . $runtime);
putenv('SESSION_SECURE=false');
putenv('APP_KEY=delivery-policy-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');
putenv('APP_URL=https://delivery-policy.example.test');
putenv('EXTERNAL_DELIVERY_ENABLED=false');
putenv('TELEGRAM_NETWORK_ENABLED=false');
putenv('TELEGRAM_ALERT_DELIVERY_ENABLED=false');
putenv('TELEGRAM_BUSINESS_ENABLED=false');
putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=false');
putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=false');
putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=false');
require $root . '/src/bootstrap.php';

use VazinCMS\{
    Database,
    DeliveryPolicy,
    PublicationService,
    TelegramBotApi,
    TelegramSyncService,
    Webhook
};

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expectBlocked = static function (callable $action, string $needle, string $message) use ($check): void {
    $error = null;
    try {
        $action();
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $check($error instanceof RuntimeException, $message . ' (no RuntimeException)');
    $check(str_contains($error->getMessage(), $needle), $message . ' (wrong policy error)');
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
        $seen->execute(['version'=>$version]);
        if (!$seen->fetchColumn()) $pdo->exec((string)file_get_contents($migration));
    }
    $check((bool)$pdo->query("SELECT 1 FROM schema_migrations WHERE version='10.9.0'")->fetchColumn(), '10.9.0 migration was not applied');

    $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(:name,:email,:password,'owner','active')")
        ->execute([
            'name'=>'Delivery Policy Owner',
            'email'=>'delivery-policy@example.test',
            'password'=>password_hash('contract-only', PASSWORD_DEFAULT),
        ]);
    $ownerId = (int)$pdo->lastInsertId();
    $botToken = '123456789:' . str_repeat('A', 35);

    $check(!DeliveryPolicy::externalEnabled(), 'external delivery must default to disabled');
    $check(!DeliveryPolicy::telegramNetworkGateEnabled(), 'Telegram network gate must default to disabled');
    $check(!DeliveryPolicy::telegramEnabled(), 'Telegram network must default to disabled');
    $check(!DeliveryPolicy::telegramAlertsGateEnabled(), 'travel and visa Telegram alert gate must default to disabled');
    $check(!DeliveryPolicy::telegramAlertsEnabled(), 'travel and visa Telegram alerts must default to disabled');
    $check(!DeliveryPolicy::telegramBusinessEnabled(), 'Telegram Business must default to disabled');
    $check(!DeliveryPolicy::telegramAssistantDeliveryEnabled(), 'assistant delivery must default to disabled');
    $check(!DeliveryPolicy::telegramAssistantAutoreplyEnabled(), 'assistant autoreply must default to disabled');
    $check(!DeliveryPolicy::telegramAssistantPreviewEnabled(), 'assistant preview must default to disabled');

    $transportCalls = 0;
    $api = new TelegramBotApi($botToken, static function (string $method, array $parameters) use (&$transportCalls): array {
        $transportCalls++;
        return ['ok'=>true, 'result'=>['method'=>$method, 'parameters'=>$parameters]];
    });
    $expectBlocked(
        static fn(): array => $api->call('getMe'),
        'TELEGRAM_NETWORK_ENABLED',
        'Telegram API reached its fake transport while delivery gates were disabled'
    );
    $check($transportCalls === 0, 'disabled Telegram API invoked the transport');

    $defaultConnectionId = TelegramSyncService::createConnection([
        'name'=>'Safe Default Connection',
        'chat_id'=>'-1001234567801',
        'bot_token'=>$botToken,
    ], $ownerId);
    $defaultConnection = TelegramSyncService::connectionById($defaultConnectionId);
    $check($defaultConnection['sync_mode'] === 'telegram_to_site', 'new connection did not use the safe incoming-only default');
    $check($defaultConnection['incoming_status'] === 'draft', 'new connection did not use the safe draft default');
    $check((int)$defaultConnection['auto_publish_site'] === 0, 'new connection enabled automatic site publishing by default');
    $check((int)$defaultConnection['web_app_enabled'] === 0, 'new connection enabled Telegram Web App by default');
    $check((int)$defaultConnection['is_enabled'] === 0, 'new connection was active by default');
    $check($defaultConnection['webhook_status'] === 'disabled', 'disabled connection did not record a disabled webhook state');

    $forcedConnectionId = TelegramSyncService::createConnection([
        'name'=>'Forced Off Connection',
        'chat_id'=>'-1001234567802',
        'bot_token'=>$botToken,
        'sync_mode'=>'bidirectional',
        'incoming_status'=>'published',
        'locale'=>'fa',
        'auto_publish_site'=>1,
        'web_app_enabled'=>1,
        'is_enabled'=>1,
        'manager_chat_id'=>'99887766',
    ], $ownerId);
    $forcedConnection = TelegramSyncService::connectionById($forcedConnectionId);
    $check((int)$forcedConnection['auto_publish_site'] === 0, 'disabled policy accepted automatic Telegram publishing');
    $check((int)$forcedConnection['web_app_enabled'] === 0, 'disabled policy accepted Telegram Web App activation');
    $check((int)$forcedConnection['is_enabled'] === 0, 'disabled policy accepted connection activation');
    $check($forcedConnection['webhook_status'] === 'disabled', 'forced-off connection did not record a disabled webhook state');

    TelegramSyncService::updateConnection($forcedConnectionId, [
        'sync_mode'=>'bidirectional',
        'incoming_status'=>'published',
        'locale'=>'fa',
        'auto_publish_site'=>1,
        'web_app_enabled'=>1,
        'is_enabled'=>1,
        'manager_chat_id'=>'99887766',
    ]);
    $forcedConnection = TelegramSyncService::connectionById($forcedConnectionId);
    $check((int)$forcedConnection['auto_publish_site'] === 0 && (int)$forcedConnection['web_app_enabled'] === 0 && (int)$forcedConnection['is_enabled'] === 0, 'connection update bypassed disabled Telegram policy');

    $pdo->prepare(
        "INSERT INTO cms_pages(slug,locale,title,body,status,is_home,author_id,meta_title,meta_description,canonical_url,featured_image,content_type,published_at,source_provider,source_ref,robots_index,robots_follow,og_title,og_description,schema_type) "
        . "VALUES('delivery-policy-page','fa','Delivery policy page','Contract body','published',0,:author,'','','','', 'post',CURRENT_TIMESTAMP,'','',1,1,'','','Article')"
    )->execute(['author'=>$ownerId]);
    $pageId = (int)$pdo->lastInsertId();

    $check(TelegramSyncService::queuePage($pageId) === 0, 'disabled Telegram policy queued a site page');
    $check(!TelegramSyncService::queueManagerNotice($forcedConnectionId, '99887766', 'Contract notice', 'blocked-notice'), 'disabled Telegram policy queued a manager notice');
    $check(TelegramSyncService::notifyManagers(['page_id'=>$pageId, 'title'=>'Contract warning', 'message'=>'Blocked']) === 0, 'disabled Telegram policy queued advisory notices');
    $check((int)$pdo->query('SELECT COUNT(*) FROM telegram_outbox')->fetchColumn() === 0, 'disabled Telegram policy wrote unexpected outbox rows');

    $pdo->prepare("INSERT INTO telegram_outbox(connection_id,cms_page_id,action,payload_json,dedupe_key) VALUES(:connection,:page,'send_page','{}','preexisting-disabled-outbox')")
        ->execute(['connection'=>$forcedConnectionId, 'page'=>$pageId]);
    $outboxResult = TelegramSyncService::processOutbox(10);
    $check(($outboxResult['status'] ?? '') === 'disabled' && ($outboxResult['processed'] ?? -1) === 0, 'disabled Telegram outbox processor did not fail closed');
    $outboxState = $pdo->query("SELECT status,attempts FROM telegram_outbox WHERE dedupe_key='preexisting-disabled-outbox'")->fetch();
    $check(is_array($outboxState) && $outboxState['status'] === 'pending' && (int)$outboxState['attempts'] === 0, 'disabled Telegram outbox processor mutated queued work');

    $protectedSecret = Webhook::protect('whsec_delivery_policy');
    $pdo->prepare("INSERT INTO webhook_endpoints(name,url,secret_hash,secret_encrypted,events,created_by) VALUES('Blocked Hook','https://example.com/hook',:hash,:secret,'[\"webhook.test\"]',:user)")
        ->execute([
            'hash'=>hash('sha256', 'whsec_delivery_policy'),
            'secret'=>$protectedSecret,
            'user'=>$ownerId,
        ]);
    $webhookEndpointId = (int)$pdo->lastInsertId();
    $expectBlocked(
        static fn(): int => Webhook::enqueue('webhook.test', ['blocked'=>true]),
        'EXTERNAL_DELIVERY_ENABLED',
        'Webhook enqueue was allowed while external delivery was disabled'
    );
    $check((int)$pdo->query('SELECT COUNT(*) FROM webhook_deliveries')->fetchColumn() === 0, 'disabled webhook enqueue wrote a delivery row');

    $pdo->prepare("INSERT INTO webhook_deliveries(endpoint_id,event_id,event_type,payload,status,next_attempt_at) VALUES(:endpoint,'evt_blocked_retry','webhook.test','{}','failed',CURRENT_TIMESTAMP)")
        ->execute(['endpoint'=>$webhookEndpointId]);
    $webhookDeliveryId = (int)$pdo->lastInsertId();
    $expectBlocked(
        static fn(): null => Webhook::retry($webhookDeliveryId),
        'EXTERNAL_DELIVERY_ENABLED',
        'Webhook retry was allowed while external delivery was disabled'
    );
    $webhookState = $pdo->query('SELECT status,attempt_count FROM webhook_deliveries WHERE id=' . $webhookDeliveryId)->fetch();
    $check(is_array($webhookState) && $webhookState['status'] === 'failed' && (int)$webhookState['attempt_count'] === 0, 'disabled webhook retry mutated failed delivery state');

    $pdo->prepare("INSERT INTO social_destinations(name,provider,endpoint,account_ref,encrypted_token,is_enabled) VALUES('Blocked Publisher','webhook','https://example.com/publish','contract','not-used-while-disabled',1)")->execute();
    $destinationId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO publication_jobs(page_id,status,scheduled_at,destination_ids,created_by) VALUES(:page,'scheduled','2000-01-01 00:00:00',:destinations,:user)")
        ->execute([
            'page'=>$pageId,
            'destinations'=>json_encode([$destinationId], JSON_THROW_ON_ERROR),
            'user'=>$ownerId,
        ]);
    $publicationJobId = (int)$pdo->lastInsertId();
    $publicationResult = PublicationService::processDue();
    $check(str_contains($publicationResult, 'غیرفعال') && str_contains($publicationResult, '0'), 'publication scheduler did not report disabled delivery');
    $publicationState = $pdo->query('SELECT status,attempts FROM publication_jobs WHERE id=' . $publicationJobId)->fetch();
    $check(is_array($publicationState) && $publicationState['status'] === 'scheduled' && (int)$publicationState['attempts'] === 0, 'disabled publication scheduler mutated a due job');
    $check((int)$pdo->query('SELECT COUNT(*) FROM publication_deliveries')->fetchColumn() === 0, 'disabled publication scheduler wrote delivery attempts');

    putenv('EXTERNAL_DELIVERY_ENABLED=true');
    putenv('TELEGRAM_NETWORK_ENABLED=false');
    $check(DeliveryPolicy::externalEnabled() && !DeliveryPolicy::telegramEnabled(), 'Telegram gate did not remain independent from external delivery gate');
    $expectBlocked(
        static fn(): array => $api->call('getMe'),
        'TELEGRAM_NETWORK_ENABLED',
        'Telegram API ignored its dedicated network gate'
    );
    $check($transportCalls === 0, 'Telegram fake transport ran without its dedicated network opt-in');

    putenv('TELEGRAM_NETWORK_ENABLED=true');
    $check(DeliveryPolicy::telegramNetworkGateEnabled(), 'dedicated Telegram network gate did not report explicit opt-in');
    $check(DeliveryPolicy::telegramEnabled(), 'explicit Telegram opt-in did not enable the policy');
    $check(!DeliveryPolicy::telegramAlertsEnabled(), 'travel and visa alert delivery ignored its dedicated opt-in');
    $check(!DeliveryPolicy::telegramBusinessEnabled(), 'Telegram Business ignored its dedicated opt-in');
    $identity = $api->call('getMe');
    $check(($identity['method'] ?? '') === 'getMe' && $transportCalls === 1, 'explicit Telegram opt-in did not reach the fake transport exactly once');

    putenv('TELEGRAM_ALERT_DELIVERY_ENABLED=true');
    $check(DeliveryPolicy::telegramAlertsGateEnabled(), 'dedicated travel and visa alert gate did not report explicit opt-in');
    $check(DeliveryPolicy::telegramAlertsEnabled(), 'explicit travel and visa alert opt-in did not enable the policy');

    putenv('TELEGRAM_BUSINESS_ENABLED=true');
    $check(DeliveryPolicy::telegramBusinessEnabled(), 'explicit Telegram Business opt-in did not enable the policy');
    $check(!DeliveryPolicy::telegramAssistantDeliveryEnabled(), 'assistant delivery ignored its dedicated opt-in');
    putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=true');
    $check(DeliveryPolicy::telegramAssistantDeliveryEnabled(), 'explicit assistant delivery opt-in did not enable the policy');
    $check(!DeliveryPolicy::telegramAssistantAutoreplyEnabled(), 'assistant autoreply ignored its dedicated opt-in');
    putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=true');
    $check(DeliveryPolicy::telegramAssistantAutoreplyEnabled(), 'explicit assistant autoreply opt-in did not enable the policy');
    putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=true');
    $check(DeliveryPolicy::telegramAssistantPreviewEnabled(), 'assistant preview opt-in did not enable the policy');

    $enabledConnectionId = TelegramSyncService::createConnection([
        'name'=>'Explicitly Enabled Connection',
        'chat_id'=>'-1001234567803',
        'bot_token'=>$botToken,
        'sync_mode'=>'bidirectional',
        'incoming_status'=>'published',
        'locale'=>'fa',
        'auto_publish_site'=>1,
        'web_app_enabled'=>1,
        'is_enabled'=>1,
    ], $ownerId);
    $enabledConnection = TelegramSyncService::connectionById($enabledConnectionId);
    $check((int)$enabledConnection['auto_publish_site'] === 1 && (int)$enabledConnection['web_app_enabled'] === 1 && (int)$enabledConnection['is_enabled'] === 1, 'explicit Telegram opt-in did not preserve requested activation');
    $check($enabledConnection['webhook_status'] === 'pending', 'explicitly enabled connection did not enter pending webhook state');

    putenv('EXTERNAL_DELIVERY_ENABLED=false');
    putenv('TELEGRAM_NETWORK_ENABLED=false');
    putenv('TELEGRAM_ALERT_DELIVERY_ENABLED=false');
    putenv('TELEGRAM_BUSINESS_ENABLED=false');
    putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=false');
    putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=false');
    putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=false');
    echo "Delivery policy contract: OK\n";
} finally {
    putenv('EXTERNAL_DELIVERY_ENABLED=false');
    putenv('TELEGRAM_NETWORK_ENABLED=false');
    putenv('TELEGRAM_ALERT_DELIVERY_ENABLED=false');
    putenv('TELEGRAM_BUSINESS_ENABLED=false');
    putenv('TELEGRAM_ASSISTANT_DELIVERY_ENABLED=false');
    putenv('TELEGRAM_ASSISTANT_AUTOREPLY_ENABLED=false');
    putenv('TELEGRAM_ASSISTANT_PREVIEW_ENABLED=false');
    $cleanup($runtime);
}
