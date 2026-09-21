<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/** Verifies the official release index; it never downloads or executes updates. */
final class UpdateFeedService
{
    private const FEED_URL = 'https://cms.vazin.online/releases/stable.json';
    private const OFFICIAL_HOST = 'cms.vazin.online';
    private const CACHE_SECONDS = 21600;

    public static function refresh(PDO $pdo, bool $force = false): array
    {
        $previous = self::status($pdo);
        if (!$force && isset($previous['checked_at']) && strtotime((string) $previous['checked_at']) >= time() - self::CACHE_SECONDS) {
            return $previous;
        }
        try {
            $document = self::request(self::FEED_URL);
            $release = self::validate(json_decode($document, true, 32, JSON_THROW_ON_ERROR));
            $status = version_compare($release['version'], Version::current(), '>') ? 'update_available' : 'current';
            $row = [
                'channel' => 'stable', 'current_version' => Version::current(),
                'available_version' => $release['version'], 'status' => $status,
                'feed_url' => self::FEED_URL, 'release_url' => $release['archive'],
                'checksum_url' => $release['checksum'], 'signature_url' => $release['signature'],
                'public_key_url' => $release['public_key'], 'release_notes_url' => $release['release_notes'],
                'minimum_current_version' => $release['minimum_current_version'], 'error_message' => null,
            ];
            self::store($pdo, $row);
            return self::status($pdo);
        } catch (Throwable $error) {
            self::store($pdo, [
                'channel' => 'stable', 'current_version' => Version::current(),
                'available_version' => null, 'status' => 'unavailable', 'feed_url' => self::FEED_URL,
                'release_url' => null, 'checksum_url' => null, 'signature_url' => null,
                'public_key_url' => null, 'release_notes_url' => null, 'minimum_current_version' => null,
                'error_message' => mb_substr($error->getMessage(), 0, 600),
            ]);
            return self::status($pdo);
        }
    }

    public static function status(PDO $pdo): array
    {
        try {
            $query = $pdo->query("SELECT * FROM cms_update_feed_state WHERE channel='stable' LIMIT 1");
            $row = $query ? $query->fetch() : false;
            return is_array($row) ? $row : self::empty();
        } catch (Throwable) {
            return self::empty();
        }
    }

    /** Public for contract tests: accepts only the official stable-feed shape. */
    public static function validate(mixed $document): array
    {
        if (!is_array($document) || array_diff(array_keys($document), [
            'product','channel','version','released_at','archive','checksum','signature','public_key','minimum_current_version','release_notes',
        ]) !== [] || count($document) !== 10) {
            throw new RuntimeException('Official update feed has an invalid schema.');
        }
        if (($document['product'] ?? null) !== 'VazinCMS' || ($document['channel'] ?? null) !== 'stable') {
            throw new RuntimeException('Official update feed identifies a different product.');
        }
        $version = (string) ($document['version'] ?? '');
        $minimum = (string) ($document['minimum_current_version'] ?? '');
        if (!self::semver($version) || !self::semver($minimum) || !is_string($document['released_at'] ?? null) || strtotime($document['released_at']) === false) {
            throw new RuntimeException('Official update feed has invalid release metadata.');
        }
        $urls = [];
        foreach (['archive','checksum','signature','public_key','release_notes'] as $field) {
            $urls[$field] = self::officialUrl((string) ($document[$field] ?? ''));
        }
        return ['version' => $version, 'minimum_current_version' => $minimum] + $urls;
    }

    private static function request(string $url): string
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'follow_location' => 0, 'user_agent' => 'VazinCMS/' . Version::current()]]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '' || strlen($body) > 64 * 1024) {
            throw new RuntimeException('Official update feed is unavailable.');
        }
        return $body;
    }

    private static function officialUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== self::OFFICIAL_HOST
            || isset($parts['user']) || isset($parts['pass']) || !isset($parts['path']) || str_contains((string) $parts['path'], '..')) {
            throw new RuntimeException('Official update feed contains an untrusted URL.');
        }
        return $url;
    }

    private static function semver(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private static function empty(): array
    {
        return ['channel' => 'stable', 'current_version' => Version::current(), 'status' => 'unchecked'];
    }

    private static function store(PDO $pdo, array $row): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $fields = 'channel,current_version,available_version,status,feed_url,release_url,checksum_url,signature_url,public_key_url,release_notes_url,minimum_current_version,error_message,checked_at';
        $values = ':channel,:current_version,:available_version,:status,:feed_url,:release_url,:checksum_url,:signature_url,:public_key_url,:release_notes_url,:minimum_current_version,:error_message,CURRENT_TIMESTAMP';
        $sql = $driver === 'sqlite'
            ? "INSERT INTO cms_update_feed_state($fields) VALUES($values) ON CONFLICT(channel) DO UPDATE SET current_version=:current_version2,available_version=:available_version2,status=:status2,feed_url=:feed_url2,release_url=:release_url2,checksum_url=:checksum_url2,signature_url=:signature_url2,public_key_url=:public_key_url2,release_notes_url=:release_notes_url2,minimum_current_version=:minimum_current_version2,error_message=:error_message2,checked_at=CURRENT_TIMESTAMP"
            : "INSERT INTO cms_update_feed_state($fields) VALUES($values) ON CONFLICT(channel) DO UPDATE SET current_version=EXCLUDED.current_version,available_version=EXCLUDED.available_version,status=EXCLUDED.status,feed_url=EXCLUDED.feed_url,release_url=EXCLUDED.release_url,checksum_url=EXCLUDED.checksum_url,signature_url=EXCLUDED.signature_url,public_key_url=EXCLUDED.public_key_url,release_notes_url=EXCLUDED.release_notes_url,minimum_current_version=EXCLUDED.minimum_current_version,error_message=EXCLUDED.error_message,checked_at=CURRENT_TIMESTAMP";
        $params = $row;
        if ($driver === 'sqlite') {
            foreach ($row as $key => $value) {
                if ($key !== 'channel') {
                    $params[$key . '2'] = $value;
                }
            }
        }
        $pdo->prepare($sql)->execute($params);
    }
}
