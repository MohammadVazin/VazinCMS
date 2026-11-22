<?php
declare(strict_types=1);

namespace VazinCMS;

/**
 * Built-in Sub-5ms Full-Page Edge Cache with Tag Invalidation.
 * Eliminates the need for external caching plugins (like WP Rocket / W3 Total Cache).
 */
final class PageCacheService
{
    public static function enabled(): bool
    {
        $flag = strtolower(trim((string) (getenv('PAGE_CACHE_ENABLED') ?: 'false')));
        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }

    public static function ttl(): int
    {
        $raw = (int) (getenv('PAGE_CACHE_TTL') ?: 300);
        return max(5, min(86400, $raw));
    }

    public static function isCacheableRequest(string $method, string $path): bool
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return false;
        }
        foreach (['/admin', '/api', '/login', '/logout', '/auth', '/install', '/health', '/connector', '/webhooks', '/telegram'] as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return false;
            }
        }
        if (!empty($_COOKIE['vazin_cms_session']) || !empty($_COOKIE[session_name()]) || !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $query
     * @return ?array{body:string,content_type:string,etag:string,created_at:int,tags:list<string>}
     */
    public static function get(int $siteId, string $host, string $path, array $query = []): ?array
    {
        $file = self::entryFile($siteId, $host, $path, $query);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['expires_at'], $entry['body'], $entry['etag'])) {
            @unlink($file);
            return null;
        }
        if ((int) $entry['expires_at'] < time()) {
            @unlink($file);
            return null;
        }
        foreach ((array) ($entry['tags'] ?? []) as $tag) {
            if (self::tagTimestamp((string) $tag) > (int) ($entry['created_at'] ?? 0)) {
                @unlink($file);
                return null;
            }
        }
        return $entry;
    }

    /**
     * @param array<string,mixed> $query
     * @param list<string> $tags
     */
    public static function put(
        int $siteId,
        string $host,
        string $path,
        array $query,
        string $body,
        string $contentType = 'text/html; charset=UTF-8',
        array $tags = ['content', 'settings', 'menus'],
        ?int $ttl = null
    ): string {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $now = time();
        $etag = '"' . substr(hash('sha256', $body), 0, 24) . '"';
        $payload = [
            'created_at' => $now,
            'expires_at' => $now + ($ttl ?? self::ttl()),
            'content_type' => $contentType,
            'etag' => $etag,
            'tags' => array_values(array_unique($tags)),
            'body' => $body,
        ];
        $file = self::entryFile($siteId, $host, $path, $query);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
        return $etag;
    }

    public static function invalidateTag(string $tag): void
    {
        $tag = preg_replace('/[^a-zA-Z0-9_:-]/', '_', $tag) ?: 'global';
        $dir = self::cacheDir() . '/tags';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/' . $tag . '.ts', (string) time(), LOCK_EX);
    }

    public static function flushAll(): int
    {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        foreach (glob($dir . '/*.cache.json') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private static function tagTimestamp(string $tag): int
    {
        $tag = preg_replace('/[^a-zA-Z0-9_:-]/', '_', $tag) ?: 'global';
        $file = self::cacheDir() . '/tags/' . $tag . '.ts';
        if (!is_file($file)) {
            return 0;
        }
        return (int) trim((string) @file_get_contents($file));
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function entryFile(int $siteId, string $host, string $path, array $query): string
    {
        ksort($query);
        $rawKey = $siteId . '|' . strtolower(trim($host)) . '|' . $path . '|' . http_build_query($query);
        return self::cacheDir() . '/' . hash('sha256', $rawKey) . '.cache.json';
    }

    private static function cacheDir(): string
    {
        return RuntimePaths::storage() . '/page-cache';
    }
}
