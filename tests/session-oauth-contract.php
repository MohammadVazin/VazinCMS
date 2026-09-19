<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir().'/vazincms-session-contract-'.bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700, true)) throw new RuntimeException('Session test directory could not be created.');

putenv('CMS_AUTH_MODE=vazin_id_only');
putenv('SESSION_SECURE=true');
ini_set('session.save_path', $temporary);
session_name('vazin_cms_session');
ini_set('session.use_strict_mode', '0');
$stale = 'stale'.bin2hex(random_bytes(16));
session_id($stale);
session_start();
$_SESSION = ['user_id' => 99, 'session_version' => 1];
session_write_close();

$_COOKIE['vazin_cms_session'] = $stale;
session_id($stale);
require_once $root.'/src/bootstrap.php';

use VazinCMS\SessionSecurity;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expect(session_id() === $stale && (int)($_SESSION['user_id'] ?? 0) === 99, 'Stale fixture session was not resumed.');
SessionSecurity::destroy();
$fresh = SessionSecurity::renewForOAuth();
$parameters = session_get_cookie_params();
$expect($fresh !== '' && !hash_equals($stale, $fresh), 'Destroyed session identifier was reused.');
$expect((string)ini_get('session.use_strict_mode') === '1' && (string)ini_get('session.use_only_cookies') === '1', 'Strict cookie-only mode is not active.');
$expect(!empty($parameters['secure']) && !empty($parameters['httponly']) && strcasecmp((string)$parameters['samesite'], 'Lax') === 0, 'OAuth cookie attributes are not secure.');
$expect(!isset($_SESSION['user_id']), 'Destroyed authentication data survived into OAuth session.');

$state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$_SESSION['vazin_id_oauth'][$state] = ['verifier' => 'test', 'created' => time()];
SessionSecurity::persistAndSuspend();
$_SESSION = [];
SessionSecurity::boot();
$expect(isset($_SESSION['vazin_id_oauth'][$state]), 'PKCE state was not resumable after session persistence.');
SessionSecurity::destroy();

foreach (scandir($temporary) ?: [] as $name) {
    if ($name !== '.' && $name !== '..') @unlink($temporary.'/'.$name);
}
@rmdir($temporary);
echo "Session OAuth contract: OK\n";
