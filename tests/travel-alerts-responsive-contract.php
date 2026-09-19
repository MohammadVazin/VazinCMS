<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/views/travel-alerts.php');
$css = (string) file_get_contents($root . '/public/assets/platform-1010.css');

if (substr_count($view, 'class="table-wrap" role="region" tabindex="0"') !== 4) {
    throw new RuntimeException('Travel alert tables must be keyboard-reachable regions.');
}
foreach (['گیت‌های تحویل', 'آمادگی اتصال ربات', 'اشتراک‌های Telegram', 'صف تحویل Telegram'] as $label) {
    if (!str_contains($view, 'aria-label="' . $label . '"')) {
        throw new RuntimeException('Travel alert table region label is missing: ' . $label);
    }
}
foreach (['.table-wrap { min-width:0; max-width:100%; overflow-x:auto;', '.table-wrap:focus-visible { outline:3px solid'] as $required) {
    if (!str_contains($css, $required)) {
        throw new RuntimeException('Responsive table containment guard is missing: ' . $required);
    }
}

echo "Travel alert responsive table contract: OK\n";
