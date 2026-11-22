<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SiteIsolationGate.php';

use VazinCMS\SiteIsolationGate;

$assert = static function (bool $ok, string $reason): void {
    if (!$ok) {
        throw new RuntimeException('SITE_ISOLATION_FAIL: ' . $reason);
    }
};
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY, site_key TEXT, status TEXT)');
$pdo->exec('CREATE TABLE cms_site_domains(site_id INTEGER NOT NULL, domain TEXT, verified_at TEXT)');

$pdo->exec("INSERT INTO cms_sites VALUES(1,'default','active')");
$assert(SiteIsolationGate::evaluate($pdo, 'localhost:8080') === 'ok', 'legacy single-site/no domains');
$pdo->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY,site_id INTEGER NOT NULL)');
$assert(SiteIsolationGate::evaluate($pdo, 'localhost:8080') === 'host_unverified',
    'migrated schema must not accept arbitrary Host without verified domain');
$pdo->exec('DROP TABLE cms_pages');
$pdo->exec("INSERT INTO cms_site_domains VALUES(1,'cms.example.test','2026-11-01 00:00:00')");
$assert(SiteIsolationGate::evaluate($pdo, 'cms.example.test') === 'ok', 'verified canonical host');
$assert(SiteIsolationGate::evaluate($pdo, 'CMS.EXAMPLE.TEST:443') === 'ok', 'case and port normalization');
$assert(SiteIsolationGate::evaluate($pdo, 'unknown.example.test') === 'host_unverified', 'unknown host rejected');
$assert(SiteIsolationGate::evaluate($pdo, 'cms.example.test.evil') === 'host_unverified', 'suffix spoof rejected');
$assert(SiteIsolationGate::evaluate($pdo, 'cms..example.test') === 'host_unverified', 'invalid hostname rejected');
$pdo->exec("INSERT INTO cms_site_domains VALUES(1,'pending.example.test',NULL)");
$assert(SiteIsolationGate::evaluate($pdo, 'pending.example.test') === 'host_unverified', 'unverified domain rejected');
$pdo->exec("INSERT INTO cms_sites VALUES(2,'tenant-b','active')");
$pdo->exec("INSERT INTO cms_site_domains VALUES(2,'tenant-b.example.test','2026-11-01 00:00:00')");
foreach (['cms.example.test','tenant-b.example.test','unknown.example.test'] as $host) {
    $assert(SiteIsolationGate::evaluate($pdo, $host) === 'multisite_unisolated',
        'all domains must fail closed in shared multi-site mode: ' . $host);
}
$pdo->exec("UPDATE cms_sites SET status='suspended' WHERE id=2");
$assert(SiteIsolationGate::evaluate($pdo, 'cms.example.test') === 'multisite_unisolated',
    'an inactive second site is still unsafe because shared content has no site_id');
$pdo->exec('DELETE FROM cms_sites');
$assert(SiteIsolationGate::evaluate($pdo, 'cms.example.test') === 'multisite_unisolated',
    'zero-site inconsistency fails closed');

$oldAccept = $_SERVER['HTTP_ACCEPT'] ?? null;
$_SERVER['HTTP_ACCEPT'] = 'application/json';
ob_start();
SiteIsolationGate::reject('multisite_unisolated', '/api/v3/content');
$body = (string) ob_get_clean();
$parsed = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
$assert(http_response_code() === 409, 'multi-site rejection status 409');
$assert(($parsed['code'] ?? null) === 'multisite_unisolated', 'API gives machine-readable failure');
$assert(!str_contains($body, 'tenant-b.example.test'), 'response contains no tenant data');
ob_start();
SiteIsolationGate::reject('host_unverified', '/search');
$body = (string) ob_get_clean();
$assert(http_response_code() === 421, 'unknown host rejection status 421');
$assert((json_decode($body, true)['code'] ?? null) === 'host_unverified', 'search JSON rejection');
if ($oldAccept === null) {
    unset($_SERVER['HTTP_ACCEPT']);
} else {
    $_SERVER['HTTP_ACCEPT'] = $oldAccept;
}

$app = file_get_contents(dirname(__DIR__) . '/src/App.php');
$assert(is_string($app), 'app source readable');
$gatePos = strpos($app, "SiteIsolationGate::evaluate(Database::connection())");
$dispatchPos = strpos($app, 'if($this->dispatchCore($path))');
$resolvePos = strpos($app, 'SiteContext::resolve(Database::connection())');
$assert($gatePos !== false && $dispatchPos !== false && $resolvePos !== false &&
        $gatePos < $resolvePos && $gatePos < $dispatchPos,
    'the gate runs before all dynamic route dispatch');

echo "Site isolation gate contract: PASS (single-site, domain spoofing, two tenants, all dynamic routes, API, status)\n";
