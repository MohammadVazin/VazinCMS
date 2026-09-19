<?php
declare(strict_types=1);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Admin brand locale render contract: SKIPPED (pdo_sqlite unavailable)\n";
    exit(0);
}

$root = dirname(__DIR__);
$database = tempnam(sys_get_temp_dir(), 'vazincms-admin-locale-');
if ($database === false) throw new RuntimeException('Temporary locale database could not be created.');
$runtime = tempnam(sys_get_temp_dir(), 'vazincms-admin-runtime-');
if ($runtime === false) throw new RuntimeException('Temporary locale runtime could not be created.');
@unlink($runtime);
if (!mkdir($runtime, 0700, true) && !is_dir($runtime)) throw new RuntimeException('Temporary locale runtime could not be initialized.');
$storage = $runtime . '/storage';
$uploads = $runtime . '/uploads';
foreach ([$storage, $uploads] as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Temporary locale runtime directory could not be initialized.');
}

putenv('SESSION_SECURE=false');
putenv('DEFAULT_LOCALE');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . $database);
putenv('VAZINCMS_STORAGE_PATH=' . $storage);
putenv('VAZINCMS_UPLOADS_PATH=' . $uploads);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'VazinCMS contract';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
$_GET = [];
$_COOKIE = [];
require $root . '/src/bootstrap.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$render = static function (): string {
    ob_start();
    VazinCMS\View::render('error', ['title' => 'Locale contract', 'message' => 'Safe test render.']);
    return (string) ob_get_clean();
};
$htmlLocale = static function (string $html): string {
    if (preg_match('/<html lang="([a-z]{2})" dir="(rtl|ltr)"/', $html, $match) !== 1) {
        throw new RuntimeException('Rendered admin document has no valid language or direction.');
    }
    return $match[1] . ':' . $match[2];
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) $removeTree($child);
        elseif (is_file($child) || is_link($child)) @unlink($child);
    }
    @rmdir($path);
};

try {
    $pdo = VazinCMS\Database::connection();
    $pdo->exec((string) file_get_contents($root . '/database/schema-sqlite.sql'));
    $migrations = glob($root . '/database/migrations/*-sqlite.sql') ?: [];
    usort($migrations, static fn(string $left, string $right): int => version_compare(
        explode('-', basename($left))[0],
        explode('-', basename($right))[0]
    ));
    foreach ($migrations as $migration) {
        $version = explode('-', basename($migration))[0];
        $exists = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');
        $exists->execute(['version' => $version]);
        if ($exists->fetchColumn()) continue;
        $pdo->beginTransaction();
        try {
            $pdo->exec((string) file_get_contents($migration));
            $pdo->prepare('INSERT OR IGNORE INTO schema_migrations(version) VALUES(:version)')->execute(['version' => $version]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    VazinCMS\SchemaHealth::repairAuthentication();

    $owner = $pdo->prepare("INSERT INTO users(name,email,password_hash,role,status) VALUES(:name,:email,:hash,'owner','active')");
    $owner->execute(['name' => 'Locale Owner', 'email' => 'locale-owner@example.invalid', 'hash' => password_hash('not-used', PASSWORD_DEFAULT)]);
    $ownerId = (int) $pdo->lastInsertId();
    $setting = $pdo->prepare('INSERT INTO cms_settings(setting_key,setting_value) VALUES(:key,:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:value2,updated_at=CURRENT_TIMESTAMP');
    $setDefault = static function (string $locale) use ($setting): void {
        $setting->execute(['key' => 'default_locale', 'value' => $locale, 'value2' => $locale]);
    };
    $setDefault('fa');
    VazinCMS\ExtensionManager::reset();
    VazinCMS\ExtensionManager::synchronize(true);
    VazinCMS\Auth::login($ownerId);

    $_GET = [];
    $_COOKIE = [];
    $check($htmlLocale($render()) === 'fa:rtl', 'Configured Persian brand was not rendered as RTL for an authenticated admin.');

    $_GET = [];
    $_COOKIE = ['vazincms_locale' => 'en'];
    $check($htmlLocale($render()) === 'en:ltr', 'Explicit user locale did not override configured brand locale.');

    $_GET = [];
    $_COOKIE = [];
    $identity = $pdo->prepare('INSERT INTO user_identities(user_id,provider,provider_subject,email,email_verified,locale) VALUES(:user_id,:provider,:subject,:email,1,:locale)');
    $identity->execute(['user_id' => $ownerId, 'provider' => 'vazin_id', 'subject' => 'locale-contract-owner', 'email' => 'locale-owner@example.invalid', 'locale' => 'ru']);
    $check($htmlLocale($render()) === 'ru:ltr', 'Vazin ID locale did not override configured brand locale.');

    putenv('DEFAULT_LOCALE=ar');
    $_GET = [];
    $_COOKIE = [];
    $check($htmlLocale($render()) === 'ru:ltr', 'Vazin ID locale did not override environment locale.');
    $pdo->exec('UPDATE user_identities SET locale=NULL');
    $check($htmlLocale($render()) === 'ar:rtl', 'Environment locale did not override configured brand locale.');
    putenv('DEFAULT_LOCALE');

    $_GET = ['ui_lang' => 'en'];
    $_COOKIE = [];
    $check($htmlLocale($render()) === 'en:ltr', 'Query locale did not override configured brand locale.');

    $_GET = [];
    $_COOKIE = [];
    $setDefault('de');
    $check($htmlLocale($render()) === 'en:ltr', 'Invalid configured brand locale did not fall back to browser locale.');
    $pdo->exec("DELETE FROM cms_settings WHERE setting_key='default_locale'");
    $check($htmlLocale($render()) === 'en:ltr', 'Missing configured brand locale did not fall back to browser locale.');

    VazinCMS\Auth::logout();
    $reflection = new ReflectionClass(VazinCMS\Database::class);
    $connection = $reflection->getProperty('pdo');
    $connection->setValue(null);
    putenv('DB_DATABASE=' . sys_get_temp_dir() . '/vazincms-missing-parent/database.sqlite');
    $_GET = [];
    $_COOKIE = [];
    $check($htmlLocale($render()) === 'en:ltr', 'Unauthenticated no-database render did not preserve browser fallback.');
} finally {
    @unlink($database);
    $removeTree($runtime);
}

echo "Admin brand locale render contract: OK\n";
