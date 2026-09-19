<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$css = (string) file_get_contents($root . '/public/assets/theme-90.css');
$check(str_contains($css, '.travel-footer{width:min(1180px,calc(100% - 32px));margin:auto;padding:35px 0;border-top:1px solid var(--vz-border);display:grid;grid-template-columns:1fr auto;gap:10px;color:var(--vz-muted)}'), 'Theme footer base rule is missing.');
$platformCss = (string) file_get_contents($root . '/public/assets/platform-1010.css');
$check(str_contains($platformCss, '.travel-footer { color: var(--v-ui-muted); }'), 'Platform footer contrast override is missing.');

$relativeLuminance = static function (string $hex): float {
    $channels = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $linear = array_map(static function (int $channel): float {
        $value = $channel / 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, $channels);
    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
};
$contrast = static function (string $foreground, string $background) use ($relativeLuminance): float {
    $a = $relativeLuminance($foreground);
    $b = $relativeLuminance($background);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
};

$check($contrast('#627087', '#f4f7fb') >= 4.5, 'Light footer text must meet WCAG AA contrast.');
$check($contrast('#afbed0', '#0b1422') >= 4.5, 'Dark footer text must meet WCAG AA contrast.');

echo "Travel/Visa footer contrast contract: OK\n";
