<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . $path);
    if (!is_string($value)) throw new RuntimeException('Could not read alert readiness source: ' . $path);
    return $value;
};

$policy = $read('/src/DeliveryPolicy.php');
$controller = $read('/src/Controllers/TelegramAlertController.php');
$view = $read('/views/travel-alerts.php');

$check(str_contains($policy, 'function telegramNetworkGateEnabled') && str_contains($policy, 'function telegramAlertsGateEnabled'), 'Read-only gate indicators are incomplete.');
$check(str_contains($controller, '$deliveryGates') && str_contains($controller, '$connectionReadiness') && str_contains($controller, '$alertPreview'), 'Alert readiness data is not prepared by the controller.');
$check(str_contains($view, 'آمادگی و پیش‌نمایش هشدارها') && str_contains($view, 'فقط خواندنی') && str_contains($view, 'نمونهٔ پیامِ حریم‌خصوصی‌محور'), 'Read-only alert readiness view is incomplete.');
$check(str_contains($view, 'شناسهٔ چت هم نمایش داده نمی‌شوند') && !str_contains($view, 'bot_token') && !str_contains($view, 'telegram_chat_id'), 'Alert readiness view can expose sensitive connection values.');

echo "Telegram alert readiness contract: OK\n";
