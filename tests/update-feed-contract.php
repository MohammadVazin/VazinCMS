<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use VazinCMS\UpdateFeedService;

$valid = ['product'=>'VazinCMS','channel'=>'stable','version'=>'10.29.0','released_at'=>'2026-09-19T00:00:00Z','archive'=>'https://cms.vazin.online/releases/VazinCMS-10.29.0.zip','checksum'=>'https://cms.vazin.online/releases/VazinCMS-10.29.0.zip.sha256','signature'=>'https://cms.vazin.online/releases/VazinCMS-10.29.0.zip.sig','public_key'=>'https://cms.vazin.online/releases/vazincms-release-public.pem','minimum_current_version'=>'10.28.0','release_notes'=>'https://cms.vazin.online/changelog/'];
$release = UpdateFeedService::validate($valid);
if ($release['version'] !== '10.29.0' || $release['archive'] !== $valid['archive']) throw new RuntimeException('Valid official feed rejected.');
foreach ([array_replace($valid, ['product'=>'OtherCMS']), array_replace($valid, ['archive'=>'https://evil.example/a.zip']), array_replace($valid, ['version'=>'latest'])] as $invalid) {
    try { UpdateFeedService::validate($invalid); throw new RuntimeException('Invalid update feed accepted.'); } catch (RuntimeException) {}
}
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE schema_migrations(version TEXT PRIMARY KEY)');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/10.29.0-sqlite.sql'));
$pdo->exec("INSERT INTO cms_update_feed_state(channel,current_version,status,feed_url) VALUES('stable','10.28.0','current','https://cms.vazin.online/releases/stable.json')");
if ((UpdateFeedService::status($pdo)['current_version'] ?? null) !== '10.28.0') throw new RuntimeException('Update-feed migration state is unreadable.');
echo "Update feed contract: OK\n";
