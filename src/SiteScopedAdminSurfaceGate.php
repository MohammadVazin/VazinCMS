<?php
declare(strict_types=1);

namespace VazinCMS;

/**
 * Audited admin route allowlist for site-scoped installations.
 */
final class SiteScopedAdminSurfaceGate
{
    private const AUDITED = [
        '/admin' => true,
        '/admin/pages' => true,
        '/admin/pages/autosave' => true,
        '/admin/pages/restore-trash' => true,
        '/admin/menus' => true,
        '/admin/media' => true,
        '/admin/media/picker' => true,
        '/admin/revisions' => true,
        '/admin/settings' => true,
        '/admin/seo' => true,
        '/admin/content-migration' => true,
        '/admin/extensions' => true,
        '/admin/modules' => true,
        '/admin/content-types' => true,
        '/admin/blueprints' => true,
        '/admin/commerce' => true,
        '/admin/lms' => true,
        '/admin/booking' => true,
        '/admin/forms' => true,
    ];

    public static function allowed(string $path): bool
    {
        return isset(self::AUDITED[$path]);
    }

    public static function assertPath(string $path): void
    {
        if (!self::allowed($path)) {
            throw new \RuntimeException('tenant_admin_route_unreviewed');
        }
    }
}
