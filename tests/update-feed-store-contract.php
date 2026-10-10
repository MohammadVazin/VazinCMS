<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\UpdateFeedService;

$pdo=new PDO('sqlite::memory:');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$pdo->exec(<<<'SQL'
CREATE TABLE cms_update_feed_state(
    channel TEXT PRIMARY KEY,
    current_version TEXT NOT NULL,
    available_version TEXT,
    status TEXT NOT NULL
        CHECK(status IN(
            'unchecked',
            'current',
            'update_available',
            'unavailable'
        )),
    feed_url TEXT NOT NULL,
    release_url TEXT,
    checksum_url TEXT,
    signature_url TEXT,
    public_key_url TEXT,
    release_notes_url TEXT,
    minimum_current_version TEXT,
    error_message TEXT,
    checked_at TEXT NOT NULL
        DEFAULT CURRENT_TIMESTAMP
)
SQL);

$fixture = [
    'product' => 'VazinCMS',
    'channel' => 'stable',
    'version' => '10.30.3',
    'released_at' => '2026-10-10T10:00:00Z',
    'archive' => 'https://cms.vazin.online/releases/VazinCMS-10.30.3.zip',
    'checksum' => 'https://cms.vazin.online/releases/VazinCMS-10.30.3.zip.sha256',
    'signature' => 'https://cms.vazin.online/releases/VazinCMS-10.30.3.zip.sig',
    'public_key' => 'https://cms.vazin.online/releases/vazincms-release-public.pem',
    'minimum_current_version' => '10.29.0',
    'release_notes' => 'https://cms.vazin.online/releases/VazinCMS-10.30.3.md',
];
// This fixture is for deterministic testing only; it is NOT a published feed.
$fetch = static function (string $url) use ($fixture): string {
    if ($url !== 'https://cms.vazin.online/releases/stable.json') {
        throw new RuntimeException('Unexpected update-feed destination.');
    }
    return json_encode($fixture, JSON_THROW_ON_ERROR);
};
$result=UpdateFeedService::refresh($pdo, true, $fetch);

if (($result['status'] ?? '') !== 'current') {
    throw new RuntimeException(
        'Expected current status.'
    );
}

if (($result['current_version'] ?? '') !== '10.30.3') {
    throw new RuntimeException(
        'Current version mismatch.'
    );
}

if (($result['available_version'] ?? '') !== '10.30.3') {
    throw new RuntimeException(
        'Feed version mismatch.'
    );
}

if (($result['minimum_current_version'] ?? '') !== '10.29.0') {
    throw new RuntimeException(
        'Minimum version mismatch.'
    );
}

$stored=UpdateFeedService::status($pdo);

if (($stored['status'] ?? '') !== 'current') {
    throw new RuntimeException(
        'Persisted status mismatch.'
    );
}

if (($stored['available_version'] ?? '') !== '10.30.3') {
    throw new RuntimeException(
        'Persisted feed version mismatch.'
    );
}

echo "update-feed-store-contract: OK\n";
