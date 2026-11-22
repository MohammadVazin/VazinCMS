<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;

/**
 * Temporary fail-closed policy for the legacy shared-content data model.
 *
 * cms_pages, cms_media, menus, revisions, APIs and public routes still have
 * unscoped queries. A workspace-only check does not prevent cross-site reads.
 * Until a complete site_id migration and negative HTTP tests are accepted,
 * block ALL dynamic routes whenever more than one CMS site is registered.
 *
 * For a single-site installation, verified domains (if any) also restrict
 * which Host headers may reach the shared content. Never infer a site from an
 * unknown domain. This guard does not certify multi-tenant safety.
 */
final class SiteIsolationGate
{
    public static function evaluate(PDO $pdo, ?string $host = null): string
    {
        $sites = (int) $pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn();
        if ($sites !== 1) {
            return 'multisite_unisolated';
        }

        $host = strtolower(trim((string) ($host ?? ($_SERVER['HTTP_HOST'] ?? ''))));
        $host = preg_replace('/:[0-9]+$/D', '', $host) ?? '';
        if ($host === '' || strlen($host) > 253 ||
            preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) !== 1 ||
            str_contains($host, '..')) {
            return 'host_unverified';
        }

        // Existing single-site installs may have no domain rows. Their
        // historical virtual-host binding stays intact until a migration
        // explicitly registers their canonical domain.
        $verified = (int) $pdo->query(
            'SELECT COUNT(*) FROM cms_site_domains WHERE verified_at IS NOT NULL'
        )->fetchColumn();
        if ($verified === 0) {
            // Keep historical legacy single-site boot behavior only when
            // cms_pages has never been migrated. Any scoped content schema
            // MUST register a verified canonical domain before public/API use.
            $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver==='sqlite') {
                foreach ($pdo->query('PRAGMA table_info("cms_pages")')
                             ->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    if (($column['name']??'')==='site_id') {
                        return 'host_unverified';
                    }
                }
            } elseif ($driver==='pgsql') {
                $query=$pdo->query(
                    "SELECT 1 FROM information_schema.columns " .
                    "WHERE table_schema=current_schema() AND table_name='cms_pages' " .
                    "AND column_name='site_id' LIMIT 1"
                );
                if ($query->fetchColumn()) return 'host_unverified';
            } else {
                return 'host_unverified';
            }
            return 'ok';
        }

        $query = $pdo->prepare(
            'SELECT 1 FROM cms_site_domains d ' .
            'JOIN cms_sites s ON s.id=d.site_id ' .
            'WHERE lower(d.domain)=:host AND d.verified_at IS NOT NULL ' .
            'AND s.status IN (\'active\',\'staging\') LIMIT 1'
        );
        $query->execute(['host' => $host]);
        return $query->fetchColumn() ? 'ok' : 'host_unverified';
    }

    public static function reject(string $reason, string $path): void
    {
        $status = $reason === 'host_unverified' ? 421 : 409;
        http_response_code($status);
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        header('Vary: Accept');
        $isJson = $path === '/search' ||
            str_starts_with($path, '/api/') ||
            str_starts_with($path, '/connector/') ||
            str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'code' => $reason,
                'message' => 'This site is unavailable until its domain and data isolation are verified.',
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>دسترسی سایت موقتاً محدود است</title></head>'
            . '<body style="max-width:40rem;margin:12vh auto;padding:1.5rem;font:1rem Tahoma,sans-serif;line-height:1.9">'
            . '<main><h1>دسترسی سایت موقتاً محدود است</h1>'
            . '<p>تا تأیید دامنه و جداسازی داده‌های سایت‌ها، این مسیر در دسترس نیست.</p>'
            . '</main></body></html>';
    }
}
