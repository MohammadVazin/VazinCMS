<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{BlockRegistry, BlueprintManager, Database, DynamicContentService, ExtensionRuntime, SiteContext, SiteScopeMode, Version};

/**
 * Public Read-Only Headless CMS API v1 for Jamstack, Mobile Apps, and External Frontends.
 * Only exposes published content and rest_exposed dynamic content types scoped to the current site.
 */
final class HeadlessApiController
{
    public function status(): void
    {
        ExtensionRuntime::ensureBooted();
        $this->json([
            'product' => 'VazinCMS',
            'version' => Version::current(),
            'architecture' => 'microkernel-v2',
            'registered_blocks' => array_keys(BlockRegistry::catalog()),
            'blueprints' => array_keys(BlueprintManager::catalog()),
        ]);
    }

    public function types(): void
    {
        $pdo = Database::connection();
        $types = array_filter(
            DynamicContentService::allTypes($pdo),
            static fn(array $t): bool => !empty($t['public']) && !empty($t['rest_exposed'])
        );
        $this->json(['items' => array_values($types)]);
    }

    public function entries(string $typeKey): void
    {
        $pdo = Database::connection();
        $type = DynamicContentService::getType($pdo, $typeKey);
        if ($type === null || empty($type['public']) || empty($type['rest_exposed'])) {
            $this->error('not_found', 'نوع محتوا یافت نشد یا عمومی نیست.', 404);
            return;
        }
        $locale = isset($_GET['locale']) && is_string($_GET['locale']) ? trim($_GET['locale']) : '';
        $search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        if (in_array($typeKey, ['page', 'post'], true)) {
            $scoped = SiteScopeMode::active($pdo);
            $where = ["trash_status = 'active'", "status = 'published'", 'content_type = :ct'];
            $args = ['ct' => $typeKey];
            if ($scoped) {
                $where[] = 'site_id = :site';
                $args['site'] = SiteContext::id();
            }
            if ($locale !== '') {
                $where[] = 'locale = :locale';
                $args['locale'] = $locale;
            }
            $sql = 'SELECT id, slug, locale, title, content_type, published_at, updated_at FROM cms_pages WHERE '
                . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            $this->json(['type' => $typeKey, 'items' => $stmt->fetchAll()]);
            return;
        }

        $items = DynamicContentService::queryEntries($pdo, $typeKey, [
            'status' => 'published',
            'locale' => $locale,
            'search' => $search,
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $this->json(['type' => $typeKey, 'items' => $items]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        echo json_encode([
            'ok' => true,
            'api_version' => '1.0',
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error(string $code, string $message, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
