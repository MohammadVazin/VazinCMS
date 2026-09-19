<?php
declare(strict_types=1);

use VazinCMS\TelegramHistoryImporter;

require dirname(__DIR__) . '/src/bootstrap.php';

$arguments = getopt('', ['connection:','file:','type::']);
$connection = (int)($arguments['connection'] ?? 0);
$file = (string)($arguments['file'] ?? '');
$type = (string)($arguments['type'] ?? 'telegram_export');
if ($connection < 1 || $file === '') {
    fwrite(STDERR, "Usage: php scripts/import-telegram.php --connection=ID --file=result.json [--type=telegram_export|mtproto]\n");
    exit(2);
}
try {
    $result = TelegramHistoryImporter::importFile($connection, $file, $type);
    echo json_encode(['ok'=>true,'result'=>$result], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
