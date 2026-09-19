<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\{
    AppointmentService,
    ExtensionManager,
    TelegramAssistantMaintenance,
    TelegramAssistantRepository,
    TelegramAssistantWorker
};

if (!ExtensionManager::isActive('module', 'telegram')) {
    echo json_encode(
        ['ok'=>true, 'status'=>'module_disabled'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit(0);
}

$repository = new TelegramAssistantRepository();
$configuredBatch = getenv('TELEGRAM_ASSISTANT_BATCH_SIZE');
$batchSize = is_string($configuredBatch) && ctype_digit($configuredBatch) ? (int)$configuredBatch : 10;
// One Telegram call may consume up to 20 seconds. Keep the default batch
// inside the five-minute systemd execution budget and fail closed on drift.
$batchSize = max(1, min(10, $batchSize));
$delivery = TelegramAssistantWorker::process($batchSize, null, 220.0);
$expiredHolds = (new AppointmentService($repository))->expireHolds(200);
$maintenance = TelegramAssistantMaintenance::run(1000);

echo json_encode(
    [
        'ok'=>true,
        'status'=>'processed',
        'batch_size'=>$batchSize,
        'delivery'=>$delivery,
        'expired_holds'=>$expiredHolds,
        'maintenance'=>$maintenance,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
