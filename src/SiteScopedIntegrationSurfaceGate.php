<?php
declare(strict_types=1);

namespace VazinCMS;

final class SiteScopedIntegrationSurfaceGate
{
    private const SITE_SAFE = [
        '/api/v3/content' => true,
        '/api/v3/forms' => true,
        '/api/v1/status' => true,
        '/api/v1/types' => true,
    ];

    public static function isIntegrationPath(string $path): bool
    {
        foreach (['/api', '/connector', '/webhooks'] as $base) {
            if ($path === $base || str_starts_with($path, $base . '/')) return true;
        }
        return false;
    }

    public static function allowed(string $path): bool
    {
        if (isset(self::SITE_SAFE[$path])) {
            return true;
        }
        if (preg_match('#^/api/v1/entries/[a-z0-9-]+$#', $path) === 1) {
            return true;
        }
        if (preg_match('#^/api/v1/modules/(vazin-commerce|vazin-lms|vazin-booking|vazin-forms|vazin-comments|vazin-analytics|vazin-memberships)(?:/[a-z0-9/_-]+)?$#', $path) === 1) {
            return true;
        }
        return false;
    }
}
