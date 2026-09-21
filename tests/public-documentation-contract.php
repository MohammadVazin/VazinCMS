<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$app = file_get_contents($root . '/src/App.php');
$controller = file_get_contents($root . '/src/Controllers/DocumentationController.php');
$view = file_get_contents($root . '/views/public/documentation.php');
$readme = file_get_contents($root . '/README.md');
$openapi = json_decode(
    (string)file_get_contents($root . '/public/openapi-v3.json'),
    true
);

$check = static function(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$check(is_string($app), 'App.php unavailable');
$check(is_string($controller), 'DocumentationController unavailable');
$check(is_string($view), 'documentation view unavailable');
$check(is_string($readme), 'README unavailable');

foreach ([
    '/docs',
    '/download',
    '/developers',
    '/extensions',
    '/themes',
    '/changelog',
] as $route) {
    $check(
        str_contains($app, "'" . $route . "'"),
        'documentation route missing: ' . $route
    );
}

$check(
    str_contains($app, "'/documentation'"),
    'documentation compatibility redirect missing'
);

$check(
    str_contains($app, "'/api/openapi.json'"),
    'public OpenAPI alias missing'
);

$check(
    str_contains($controller, "Version::current()"),
    'documentation version is not bound to application version'
);

$check(
    str_contains($controller, "docs/INSTALL-FA.md"),
    'installation documentation is not sourced from release tree'
);

$check(
    str_contains($controller, "docs/EXTENSIONS-FA.md"),
    'extension documentation is not sourced from release tree'
);

$check(
    str_contains($controller, "docs/UPGRADE-ROLLBACK-FA.md"),
    'upgrade documentation is not sourced from release tree'
);

$check(
    str_contains($view, 'rel="canonical"'),
    'documentation canonical metadata missing'
);

$check(
    str_contains($view, 'name="description"'),
    'documentation description metadata missing'
);

$check(
    str_contains($view, 'AGPL-3.0-or-later'),
    'public license notice missing'
);

$check(
    str_contains($readme, 'Current release: **10.30.2**'),
    'README current release missing'
);

$check(
    is_array($openapi)
    && ($openapi['openapi'] ?? null) === '3.1.0',
    'OpenAPI 3.1 specification invalid'
);

$check(
    ($openapi['info']['title'] ?? null) === 'VazinCMS Developer API',
    'OpenAPI title mismatch'
);

echo "Public documentation contract: OK\n";
