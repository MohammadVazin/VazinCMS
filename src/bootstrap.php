<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($key) === false) putenv($key . '=' . trim($value, "\"'"));
    }
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'VazinCMS\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
\VazinCMS\SessionSecurity::boot();
