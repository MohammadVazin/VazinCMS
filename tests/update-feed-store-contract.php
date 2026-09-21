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

$result=UpdateFeedService::refresh(
    $pdo,
    true
);

if (($result['status'] ?? '') !== 'current') {
    throw new RuntimeException(
        'Expected current status.'
    );
}

if (($result['current_version'] ?? '') !== '10.30.2') {
    throw new RuntimeException(
        'Current version mismatch.'
    );
}

if (($result['available_version'] ?? '') !== '10.30.2') {
    throw new RuntimeException(
        'Feed version mismatch.'
    );
}

if (($result['minimum_current_version'] ?? '') !== '10.30.0') {
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

if (($stored['available_version'] ?? '') !== '10.30.2') {
    throw new RuntimeException(
        'Persisted feed version mismatch.'
    );
}

echo "update-feed-store-contract: OK\n";
