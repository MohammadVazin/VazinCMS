<?php
declare(strict_types=1);

$root = dirname(__DIR__);
putenv('SESSION_SECURE=false');
putenv('DEFAULT_LOCALE');
require $root . '/src/bootstrap.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
$_GET = [];
$_COOKIE = [];
$check(VazinCMS\UiLocale::detect(null, null, 'fa') === 'fa', 'Configured brand locale did not override browser fallback.');
VazinCMS\UiLocale::boot(null, null, 'fa');
$check(VazinCMS\UiLocale::locale() === 'fa' && VazinCMS\UiLocale::direction() === 'rtl', 'Configured Persian brand did not render RTL.');

$_GET = [];
$_COOKIE = ['vazincms_locale' => 'en'];
$check(VazinCMS\UiLocale::detect(null, 'ar', 'fa') === 'en', 'Explicit user locale did not override identity and brand defaults.');

$_GET = [];
$_COOKIE = [];
$check(VazinCMS\UiLocale::detect(null, 'ru', 'fa') === 'ru', 'Vazin ID locale did not override brand default.');

putenv('DEFAULT_LOCALE=ar');
$_GET = [];
$_COOKIE = [];
$check(VazinCMS\UiLocale::detect(null, null, 'fa') === 'ar', 'Environment locale did not override configured brand default.');
putenv('DEFAULT_LOCALE');

$_GET = ['ui_lang' => 'ru'];
$_COOKIE = [];
$check(VazinCMS\UiLocale::detect(null, null, 'fa') === 'ru', 'Query locale did not override configured brand default.');

$_GET = [];
$_COOKIE = [];
$check(VazinCMS\UiLocale::detect(null, null, 'de') === 'en', 'Invalid configured brand locale did not fall back safely.');

$view = (string) file_get_contents($root . '/src/View.php');
$check(str_contains($view, '$brandDefault=is_array($currentUser)?self::configuredDefaultLocale():null;'), 'Unauthenticated rendering must not query the configured brand locale.');
$check(str_contains($view, "setting_key=:key LIMIT 1"), 'Authenticated rendering does not load the configured brand locale.');
$check(str_contains($view, "UiLocale::boot(null,is_array(\$currentUser)?(string)(\$currentUser['identity_locale']??''):null,\$brandDefault);"), 'Authenticated rendering does not pass the configured brand locale to UI locale detection.');

echo "Admin brand locale contract: OK\n";
