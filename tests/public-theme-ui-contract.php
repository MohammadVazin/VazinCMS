<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$themeHead = (string) file_get_contents($root . '/extensions/themes/vazin-default/views/head.php');
$platformCss = (string) file_get_contents($root . '/public/assets/platform-1010.css');
foreach ([
    '<link rel="preload" href="/assets/Vazirmatn.woff2" as="font" type="font/woff2" crossorigin>',
    '/assets/platform-1010.css?v=',
    "ThemeManager::assetUrl('theme.css'))?>?v=1.1.1",
] as $needle) {
    $check(str_contains($themeHead, $needle), 'Active bundled theme omits required public UI asset: ' . $needle);
}
$check(
    strpos($themeHead, '/assets/theme-90.css') < strpos($themeHead, '/assets/platform-1010.css')
        && strpos($themeHead, '/assets/platform-1010.css') < strpos($themeHead, "ThemeManager::assetUrl('theme.css')"),
    'Active bundled theme must load the shared platform CSS after base styles and before theme overrides.'
);
foreach ([
    'font-family: "Vazirmatn"',
    'font-weight: 100 900',
    'font-display: swap',
    'button,',
    'select,',
    'textarea { font: inherit; }',
    'button:focus-visible',
    '.platform-shell',
] as $needle) {
    $check(str_contains($platformCss, $needle), 'Platform UI stylesheet is missing required contract: ' . $needle);
}

echo "Public theme UI contract: OK\n";
