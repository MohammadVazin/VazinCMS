<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir() . '/vazincms-health-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);

putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . $runtime . '/missing/database.sqlite');
putenv('VAZINCMS_STORAGE_PATH=' . $runtime);
putenv('SESSION_SECURE=false');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/health';

require $root . '/src/bootstrap.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$request = static function (): array {
    http_response_code(200);
    ob_start();
    (new VazinCMS\App())->run();
    $body = (string)ob_get_clean();
    $json = json_decode($body, true);
    return ['status'=>http_response_code(), 'json'=>is_array($json) ? $json : []];
};

$failed = $request();
$check($failed['status'] === 503, 'Database failure must return HTTP 503.');
$check(($failed['json']['ok'] ?? null) === false, 'Database failure health payload must be false.');

putenv('DB_DATABASE=:memory:');
$healthy = $request();
$check($healthy['status'] === 200, 'Healthy database must return HTTP 200.');
$check(($healthy['json']['ok'] ?? null) === true, 'Healthy database health payload must be true.');

echo "Health contract: OK\n";
@rmdir($runtime);
