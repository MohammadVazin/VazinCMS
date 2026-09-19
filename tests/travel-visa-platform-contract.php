<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir() . '/vazincms-travel-visa-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('VAZINCMS_STORAGE_PATH=' . $runtime);
putenv('SESSION_SECURE=false');
putenv('APP_KEY=travel-visa-platform-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');
putenv('APP_URL=https://travel-visa.example.test');
putenv('EXTERNAL_DELIVERY_ENABLED=false');
putenv('TELEGRAM_NETWORK_ENABLED=false');
putenv('TELEGRAM_ALERT_DELIVERY_ENABLED=false');
require $root . '/src/bootstrap.php';

use VazinCMS\{Database,DeliveryPolicy,TelegramSyncService,TravelAlertService,TravelAlertWorker,VisaApplicationService};

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
    usort($migrations, static fn(string $left, string $right): int => version_compare(explode('-', basename($left))[0], explode('-', basename($right))[0]));
    foreach ($migrations as $migration) {
        $version = explode('-', basename($migration))[0];
        $seen = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');
        $seen->execute(['version'=>$version]);
        if (!$seen->fetchColumn()) $pdo->exec((string)file_get_contents($migration));
    }
    $check((bool)$pdo->query("SELECT 1 FROM schema_migrations WHERE version='10.10.0'")->fetchColumn(), '10.10.0 migration was not applied');
    foreach (['visa_products','visa_rules','visa_application_intakes','travel_provider_connections','agency_inquiries','telegram_alert_subscriptions','telegram_alert_outbox'] as $table) {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name");
        $statement->execute(['name'=>$table]);
        $check((bool)$statement->fetchColumn(), 'Missing 10.10.0 table: ' . $table);
    }

    $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES('Platform Owner','platform-owner@example.test',:hash,'owner','active')")
        ->execute(['hash'=>password_hash('contract-only', PASSWORD_DEFAULT)]);
    $ownerId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO travel_orders(public_id,access_hash,locale,service_type,full_name,email,phone,destination,nationality,travelers,travel_date,notes) "
        . "VALUES('VZPLATFORM01',:access,'fa','visa','Contract Traveller','traveller@example.test','+12025550100','RU','IR',1,'2026-10-04','')"
    )->execute(['access'=>hash('sha256','CONTRACT')]);
    $order = $pdo->query("SELECT * FROM travel_orders WHERE public_id='VZPLATFORM01'")->fetch();
    $check(is_array($order), 'Contract order was not created');

    $application = new VisaApplicationService();
    $valid = $application->validate([
        'given_name'=>'Test','family_name'=>'Traveller','birth_date'=>'1990-06-14','gender'=>'female','birth_country'=>'Iran','birth_city'=>'Tehran','nationality'=>'Iran','second_nationality'=>'',
        'passport_number'=>'P12345678','passport_issued_at'=>'2021-01-10','passport_expires_at'=>'2031-09-30','passport_issuing_country'=>'Iran','passport_issuing_authority'=>'Passport Office',
        'residence_country'=>'Russia','residence_city'=>'Moscow','residence_address'=>'Contract Test Address 12','email'=>'traveller@example.test','phone'=>'+12025550100',
        'destination'=>'Russia','visa_type'=>'tourism','entry_count'=>'single','arrival_date'=>'2026-10-04','departure_date'=>'2026-10-15','accommodation_name'=>'Contract Hotel','accommodation_address'=>'Safe Destination Address 7',
        'employment_status'=>'employed','employer_name'=>'Contract Company','employer_address'=>'Business Address 2','travel_purpose'=>'Tourism and family visit','previous_visa'=>'no','previous_visa_details'=>'',
        'emergency_contact_name'=>'Emergency Contact','emergency_contact_relation'=>'Sibling','emergency_contact_phone'=>'+12025550101','additional_information'=>'',
        'accuracy_consent'=>'1','privacy_consent'=>'1',
    ]);
    $check($valid['errors'] === [], 'Valid eVisa payload did not validate');
    $invalid = $application->validate(['passport_number'=>'bad']);
    $check(isset($invalid['errors']['passport_number']), 'Invalid passport number was accepted');
    $eventId = $application->save($order, $valid['payload']);
    $intake = $pdo->prepare('SELECT encrypted_payload,consent_at,retention_until FROM visa_application_intakes WHERE order_id=:order_id');
    $intake->execute(['order_id'=>$order['id']]);
    $intakeRow = $intake->fetch();
    $check(is_array($intakeRow) && (string)$intakeRow['consent_at'] !== '' && (string)$intakeRow['retention_until'] !== '', 'eVisa intake metadata was not persisted');
    $check(!str_contains((string)$intakeRow['encrypted_payload'], 'P12345678'), 'Passport number was stored in plaintext');
    $check($eventId > 0, 'eVisa case event id was not returned');
    $operatorIntake = $application->forOperator((int)$order['id']);
    $check(($operatorIntake['state'] ?? '') === 'available', 'Authorized operator intake was not available.');
    $check(($operatorIntake['payload']['passport']['number'] ?? '') === 'P12345678', 'Authorized operator intake did not decrypt the expected payload.');
    $check(!array_key_exists('encrypted_payload', $operatorIntake), 'Authorized operator intake exposed cipher text to its caller.');
    $pdo->prepare("UPDATE visa_application_intakes SET retention_until='2000-01-01 00:00:00' WHERE order_id=:order_id")
        ->execute(['order_id'=>$order['id']]);
    $check(($application->forOperator((int)$order['id'])['state'] ?? '') === 'expired', 'Expired operator intake was exposed.');

    $check(!DeliveryPolicy::telegramAlertsEnabled(), 'Alert delivery was unexpectedly enabled by default');
    $check((TravelAlertWorker::process(5)['status'] ?? '') === 'disabled', 'Disabled alert worker did not fail closed');

    putenv('EXTERNAL_DELIVERY_ENABLED=true');
    putenv('TELEGRAM_NETWORK_ENABLED=true');
    putenv('TELEGRAM_ALERT_DELIVERY_ENABLED=true');
    $check(DeliveryPolicy::telegramAlertsEnabled(), 'Alert delivery gate did not enable explicitly');
    $botToken = '123456789:' . str_repeat('A', 35);
    $connectionId = TelegramSyncService::createConnection([
        'name'=>'Travel Visa Contract Bot','chat_id'=>'-1001234567890','bot_token'=>$botToken,
        'sync_mode'=>'telegram_to_site','incoming_status'=>'draft','locale'=>'fa','is_enabled'=>1,
    ], $ownerId);
    $pdo->prepare("UPDATE telegram_connections SET bot_username='travel_contract_bot',webhook_status='active',is_enabled=1 WHERE id=:id")
        ->execute(['id'=>$connectionId]);
    $connection = TelegramSyncService::connectionById($connectionId);
    $manager = TravelAlertService::managerOptIn($connectionId, $ownerId, 'fa');
    $check(($manager['status'] ?? '') === 'pending' && str_contains((string)($manager['url'] ?? ''), 'https://t.me/travel_contract_bot?start=va_'), 'Manager opt-in link was not created');
    parse_str((string)parse_url((string)$manager['url'], PHP_URL_QUERY), $query);
    $token = (string)($query['start'] ?? '');
    $check(preg_match('/^va_[a-f0-9]{48}$/', $token) === 1, 'Manager opt-in token format is invalid');
    $subscription = $pdo->query('SELECT * FROM telegram_alert_subscriptions WHERE recipient_type=\'manager\'')->fetch();
    $check(is_array($subscription) && !str_contains((string)$subscription['consent_token_hash'], $token), 'Consent token was stored in plaintext');
    $start = TravelAlertService::handleIncomingMessage($connection, ['text'=>'/start ' . $token,'chat'=>['id'=>'99887766'],'from'=>['id'=>'99887766']]);
    $check(($start['status'] ?? '') === 'opted_in', 'Manager opt-in command did not bind consent');
    $redacted = TravelAlertService::redactedConsentUpdate(['message'=>['text'=>'/start ' . $token,'chat'=>['id'=>'99887766'],'from'=>['id'=>'99887766']]], 11);
    $encodedRedacted = json_encode($redacted, JSON_THROW_ON_ERROR);
    $check(!str_contains($encodedRedacted, $token) && !str_contains($encodedRedacted, '99887766'), 'Consent webhook redaction leaked a token or chat id');
    $queued = TravelAlertService::enqueueOrderEvent($order, 'visa_application_received', $eventId);
    $check($queued === 1, 'Manager application alert was not queued');
    $outbox = $pdo->query('SELECT payload_sealed FROM telegram_alert_outbox ORDER BY id DESC LIMIT 1')->fetch();
    $check(is_array($outbox) && !str_contains((string)$outbox['payload_sealed'], 'P12345678'), 'Alert outbox exposed passport data');
    $stopped = TravelAlertService::handleIncomingMessage($connection, ['text'=>'/stop','chat'=>['id'=>'99887766'],'from'=>['id'=>'99887766']]);
    $check(($stopped['status'] ?? '') === 'opted_out', 'Opt-out command was not honored');
    $check((int)$pdo->query("SELECT COUNT(*) FROM telegram_alert_outbox WHERE status='cancelled'")->fetchColumn() >= 1, 'Opt-out did not cancel pending alert work');

    echo "Travel/Visa platform contract: OK\n";
} finally {
    putenv('EXTERNAL_DELIVERY_ENABLED=false');
    putenv('TELEGRAM_NETWORK_ENABLED=false');
    putenv('TELEGRAM_ALERT_DELIVERY_ENABLED=false');
    $cleanup($runtime);
}
