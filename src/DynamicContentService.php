<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * High-performance Dynamic Content Engine (Custom Content Types + Typed Schema Fields).
 *
 * Replaces WordPress's slow EAV `wp_postmeta` joins with single-table structured rows
 * + validated JSON field schemas, full `site_id` multi-tenant isolation, and headless API readiness.
 */
final class DynamicContentService
{
    public const FIELD_TYPES = [
        'text', 'textarea', 'richtext', 'number', 'price', 'boolean',
        'select', 'date', 'url', 'email', 'media', 'gallery', 'relation', 'json',
    ];

    /** @var array<string,array<string,mixed>> */
    private static array $runtimeTypes = [];

    private static bool $schemaEnsured = false;

    /**
     * @param array<string,mixed> $definition
     */
    public static function registerRuntimeType(string $ownerModule, string $typeKey, array $definition): void
    {
        $typeKey = self::normalizeKey($typeKey);
        $fields = self::normalizeFields($definition['fields'] ?? []);
        self::$runtimeTypes[$typeKey] = [
            'type_key' => $typeKey,
            'label' => mb_substr(trim((string) ($definition['label'] ?? $typeKey)), 0, 120),
            'plural_label' => mb_substr(trim((string) ($definition['plural_label'] ?? ($definition['label'] ?? $typeKey))), 0, 120),
            'description' => mb_substr(trim((string) ($definition['description'] ?? '')), 0, 500),
            'owner_module' => $ownerModule,
            'public' => (bool) ($definition['public'] ?? true),
            'rest_exposed' => (bool) ($definition['rest_exposed'] ?? true),
            'supports_blocks' => (bool) ($definition['supports_blocks'] ?? true),
            'fields' => $fields,
        ];
    }

    public static function resetRuntimeTypes(): void
    {
        self::$runtimeTypes = [];
        self::$schemaEnsured = false;
    }

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idCol = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'mysql'  => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            default  => 'BIGSERIAL PRIMARY KEY',
        };
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $pdo->exec("CREATE TABLE IF NOT EXISTS cms_dynamic_types (
            id {$idCol},
            site_id BIGINT NOT NULL DEFAULT 1,
            type_key VARCHAR(80) NOT NULL,
            label VARCHAR(120) NOT NULL,
            plural_label VARCHAR(120) NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            public INTEGER NOT NULL DEFAULT 1,
            rest_exposed INTEGER NOT NULL DEFAULT 1,
            supports_blocks INTEGER NOT NULL DEFAULT 1,
            fields_json TEXT NOT NULL DEFAULT '[]',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ){$engine}");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cms_dynamic_entries (
            id {$idCol},
            site_id BIGINT NOT NULL DEFAULT 1,
            type_key VARCHAR(80) NOT NULL,
            locale VARCHAR(12) NOT NULL DEFAULT 'fa',
            slug VARCHAR(190) NOT NULL,
            title VARCHAR(255) NOT NULL,
            excerpt TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            blocks_json TEXT NOT NULL DEFAULT '',
            fields_json TEXT NOT NULL DEFAULT '{}',
            seo_json TEXT NOT NULL DEFAULT '{}',
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            author_id BIGINT NULL,
            published_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ){$engine}");

        if ($driver !== 'mysql') {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uidx_cms_dyn_types_site_key ON cms_dynamic_types(site_id, type_key)');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uidx_cms_dyn_entries_slug ON cms_dynamic_entries(site_id, type_key, locale, slug)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cms_dyn_entries_lookup ON cms_dynamic_entries(site_id, type_key, locale, status)');
        }
        self::$schemaEnsured = true;
    }

    /**
     * @param array<string,mixed> $definition
     */
    public static function defineType(PDO $pdo, string $typeKey, array $definition, ?int $siteId = null): array
    {
        self::ensureSchema($pdo);
        $typeKey = self::normalizeKey($typeKey);
        $siteId = $siteId ?? self::currentSiteId();
        $label = mb_substr(trim((string) ($definition['label'] ?? '')), 0, 120);
        if (mb_strlen($label) < 2) {
            throw new InvalidArgumentException('عنوان نوع محتوا باید حداقل ۲ کاراکتر باشد.');
        }
        $plural = mb_substr(trim((string) ($definition['plural_label'] ?? $label)), 0, 120);
        $description = mb_substr(trim((string) ($definition['description'] ?? '')), 0, 500);
        $fields = self::normalizeFields($definition['fields'] ?? []);
        $fieldsJson = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $public = !array_key_exists('public', $definition) || !empty($definition['public']) ? 1 : 0;
        $rest = !array_key_exists('rest_exposed', $definition) || !empty($definition['rest_exposed']) ? 1 : 0;
        $blocks = !array_key_exists('supports_blocks', $definition) || !empty($definition['supports_blocks']) ? 1 : 0;

        $existing = $pdo->prepare('SELECT id FROM cms_dynamic_types WHERE site_id = :s AND type_key = :k LIMIT 1');
        $existing->execute(['s' => $siteId, 'k' => $typeKey]);
        $rowId = $existing->fetchColumn();

        if ($rowId) {
            $pdo->prepare('UPDATE cms_dynamic_types SET label=:l, plural_label=:pl, description=:d, public=:pub, rest_exposed=:rest, supports_blocks=:blk, fields_json=:f, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute(['l' => $label, 'pl' => $plural, 'd' => $description, 'pub' => $public, 'rest' => $rest, 'blk' => $blocks, 'f' => $fieldsJson, 'id' => $rowId]);
        } else {
            $pdo->prepare('INSERT INTO cms_dynamic_types(site_id, type_key, label, plural_label, description, public, rest_exposed, supports_blocks, fields_json) VALUES(:s, :k, :l, :pl, :d, :pub, :rest, :blk, :f)')
                ->execute(['s' => $siteId, 'k' => $typeKey, 'l' => $label, 'pl' => $plural, 'd' => $description, 'pub' => $public, 'rest' => $rest, 'blk' => $blocks, 'f' => $fieldsJson]);
        }

        // Also mirror into cms_content_types if table exists so legacy page editor sees it seamlessly
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $syncSql = $driver === 'sqlite'
                ? 'INSERT OR IGNORE INTO cms_content_types(content_key, label, supports_blocks) VALUES(:k, :l, :b)'
                : ($driver === 'pgsql'
                    ? 'INSERT INTO cms_content_types(content_key, label, supports_blocks) VALUES(:k, :l, :b) ON CONFLICT(content_key) DO NOTHING'
                    : 'INSERT IGNORE INTO cms_content_types(content_key, label, supports_blocks) VALUES(:k, :l, :b)');
            $pdo->prepare($syncSql)->execute(['k' => $typeKey, 'l' => $label, 'b' => $blocks]);
        } catch (\Throwable) {
        }

        return self::getType($pdo, $typeKey, $siteId) ?? ['type_key' => $typeKey, 'label' => $label, 'fields' => $fields];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function allTypes(PDO $pdo, ?int $siteId = null): array
    {
        self::ensureSchema($pdo);
        ExtensionRuntime::ensureBooted();
        $siteId = $siteId ?? self::currentSiteId();

        $types = [
            'page' => [
                'type_key' => 'page',
                'label' => 'برگه',
                'plural_label' => 'برگه‌ها',
                'description' => 'صفحات ثابت و لندینگ‌های سایت',
                'owner_module' => 'core',
                'public' => true,
                'rest_exposed' => true,
                'supports_blocks' => true,
                'fields' => [],
            ],
            'post' => [
                'type_key' => 'post',
                'label' => 'نوشته',
                'plural_label' => 'نوشته‌ها',
                'description' => 'مقالات، اخبار و مطالب زمان‌دار',
                'owner_module' => 'core',
                'public' => true,
                'rest_exposed' => true,
                'supports_blocks' => true,
                'fields' => [],
            ],
        ];

        foreach (self::$runtimeTypes as $k => $rt) {
            $types[$k] = $rt;
        }

        $stmt = $pdo->prepare('SELECT * FROM cms_dynamic_types WHERE site_id = :s ORDER BY type_key');
        $stmt->execute(['s' => $siteId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = (string) $row['type_key'];
            $types[$k] = [
                'type_key' => $k,
                'label' => (string) $row['label'],
                'plural_label' => (string) ($row['plural_label'] ?: $row['label']),
                'description' => (string) $row['description'],
                'owner_module' => $types[$k]['owner_module'] ?? 'custom',
                'public' => (bool) $row['public'],
                'rest_exposed' => (bool) $row['rest_exposed'],
                'supports_blocks' => (bool) $row['supports_blocks'],
                'fields' => json_decode((string) $row['fields_json'], true) ?: ($types[$k]['fields'] ?? []),
            ];
        }

        return $types;
    }

    /**
     * @return ?array<string,mixed>
     */
    public static function getType(PDO $pdo, string $typeKey, ?int $siteId = null): ?array
    {
        $all = self::allTypes($pdo, $siteId);
        return $all[strtolower(trim($typeKey))] ?? null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function saveEntry(PDO $pdo, string $typeKey, array $input, ?int $actorId = null, ?int $siteId = null): array
    {
        self::ensureSchema($pdo);
        $siteId = $siteId ?? self::currentSiteId();
        $typeDef = self::getType($pdo, $typeKey, $siteId);
        if ($typeDef === null) {
            throw new InvalidArgumentException('نوع محتوا ثبت نشده است: ' . $typeKey);
        }

        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) < 1 || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('عنوان محتوا الزامی است (حداکثر ۲۵۵ کاراکتر).');
        }
        $locale = strtolower(trim((string) ($input['locale'] ?? 'fa')));
        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) !== 1) {
            $locale = 'fa';
        }
        $slug = self::slugify((string) ($input['slug'] ?? $title));
        $status = in_array(($input['status'] ?? 'draft'), ['draft', 'published', 'archived'], true)
            ? (string) $input['status']
            : 'draft';

        $validatedFields = self::validateFieldValues($typeDef['fields'], (array) ($input['fields'] ?? []));
        $validatedFields = ExtensionRuntime::filter('dynamic_content.fields', $validatedFields, [
            'type_key' => $typeKey,
            'site_id' => $siteId,
        ]);

        $blocksJson = '';
        if (!empty($input['blocks']) && is_array($input['blocks'])) {
            $blocksJson = BlockEditor::encode(['schema' => BlockEditor::SCHEMA, 'blocks' => $input['blocks']]);
        } elseif (is_string($input['blocks_json'] ?? null) && trim($input['blocks_json']) !== '') {
            $blocksJson = BlockEditor::encode(BlockEditor::decode($input['blocks_json']));
        }

        $seo = is_array($input['seo'] ?? null) ? [
            'meta_title' => mb_substr(trim((string) ($input['seo']['meta_title'] ?? '')), 0, 160),
            'meta_description' => mb_substr(trim((string) ($input['seo']['meta_description'] ?? '')), 0, 320),
            'canonical_url' => mb_substr(trim((string) ($input['seo']['canonical_url'] ?? '')), 0, 500),
        ] : [];

        $fieldsJson = json_encode($validatedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $seoJson = json_encode($seo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $excerpt = mb_substr(trim((string) ($input['excerpt'] ?? '')), 0, 1000);
        $body = (string) ($input['body'] ?? '');
        $publishedAt = $status === 'published' ? gmdate('Y-m-d H:i:s') : null;

        $entryId = (int) ($input['id'] ?? 0);
        if ($entryId > 0) {
            $stmt = $pdo->prepare('UPDATE cms_dynamic_entries SET locale=:locale, slug=:slug, title=:title, excerpt=:excerpt, body=:body, blocks_json=:blocks, fields_json=:fields, seo_json=:seo, status=:status, published_at=COALESCE(published_at, :pub), updated_at=CURRENT_TIMESTAMP WHERE id=:id AND site_id=:site');
            $stmt->execute([
                'locale' => $locale, 'slug' => $slug, 'title' => $title, 'excerpt' => $excerpt,
                'body' => $body, 'blocks' => $blocksJson, 'fields' => $fieldsJson, 'seo' => $seoJson,
                'status' => $status, 'pub' => $publishedAt, 'id' => $entryId, 'site' => $siteId,
            ]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO cms_dynamic_entries(site_id, type_key, locale, slug, title, excerpt, body, blocks_json, fields_json, seo_json, status, author_id, published_at) VALUES(:site, :type, :locale, :slug, :title, :excerpt, :body, :blocks, :fields, :seo, :status, :author, :pub)');
            $stmt->execute([
                'site' => $siteId, 'type' => $typeKey, 'locale' => $locale, 'slug' => $slug,
                'title' => $title, 'excerpt' => $excerpt, 'body' => $body, 'blocks' => $blocksJson,
                'fields' => $fieldsJson, 'seo' => $seoJson, 'status' => $status, 'author' => $actorId,
                'pub' => $publishedAt,
            ]);
            $entryId = (int) $pdo->lastInsertId();
        }

        PageCacheService::invalidateTag('content');
        PageCacheService::invalidateTag('type:' . $typeKey);
        ExtensionRuntime::emit('dynamic_content.saved', [
            'entry_id' => $entryId,
            'type_key' => $typeKey,
            'site_id' => $siteId,
            'status' => $status,
        ]);

        return self::findEntry($pdo, $entryId, $siteId) ?? ['id' => $entryId];
    }

    /**
     * @return ?array<string,mixed>
     */
    public static function findEntry(PDO $pdo, int $id, ?int $siteId = null): ?array
    {
        self::ensureSchema($pdo);
        $siteId = $siteId ?? self::currentSiteId();
        $stmt = $pdo->prepare('SELECT * FROM cms_dynamic_entries WHERE id = :id AND site_id = :site LIMIT 1');
        $stmt->execute(['id' => $id, 'site' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrateEntry($row) : null;
    }

    /**
     * @param array{locale?:string,status?:string,search?:string,limit?:int,offset?:int} $filters
     * @return list<array<string,mixed>>
     */
    public static function queryEntries(PDO $pdo, string $typeKey, array $filters = [], ?int $siteId = null): array
    {
        self::ensureSchema($pdo);
        $siteId = $siteId ?? self::currentSiteId();
        $where = ['site_id = :site', 'type_key = :type'];
        $bindings = ['site' => $siteId, 'type' => strtolower(trim($typeKey))];

        if (!empty($filters['locale'])) {
            $where[] = 'locale = :locale';
            $bindings['locale'] = (string) $filters['locale'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $bindings['status'] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(title LIKE :q OR excerpt LIKE :q2)';
            $q = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['search'])) . '%';
            $bindings['q'] = $q;
            $bindings['q2'] = $q;
        }

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $sql = 'SELECT * FROM cms_dynamic_entries WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::hydrateEntry($row);
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrateEntry(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['site_id'] = (int) $row['site_id'];
        $row['fields'] = json_decode((string) ($row['fields_json'] ?? '{}'), true) ?: [];
        $row['seo'] = json_decode((string) ($row['seo_json'] ?? '{}'), true) ?: [];
        unset($row['fields_json'], $row['seo_json']);
        return $row;
    }

    /**
     * @param mixed $rawFields
     * @return list<array{key:string,label:string,type:string,required:bool,options:list<string>}>
     */
    private static function normalizeFields(mixed $rawFields): array
    {
        if (!is_array($rawFields)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($rawFields as $field) {
            if (!is_array($field)) continue;
            $k = strtolower(trim((string) ($field['key'] ?? '')));
            if (preg_match('/^[a-z][a-z0-9_]{1,60}$/', $k) !== 1 || isset($seen[$k])) {
                throw new InvalidArgumentException('کلید فیلد سفارشی معتبر نیست: ' . $k);
            }
            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new InvalidArgumentException('نوع فیلد سفارشی پشتیبانی نمی‌شود: ' . $type);
            }
            $seen[$k] = true;
            $opts = [];
            if (is_array($field['options'] ?? null)) {
                foreach ($field['options'] as $o) {
                    if (is_scalar($o)) $opts[] = trim((string) $o);
                }
            }
            $out[] = [
                'key' => $k,
                'label' => mb_substr(trim((string) ($field['label'] ?? $k)), 0, 120),
                'type' => $type,
                'required' => !empty($field['required']),
                'options' => $opts,
            ];
        }
        return $out;
    }

    /**
     * @param list<array{key:string,label:string,type:string,required:bool,options:list<string>}> $schemaFields
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function validateFieldValues(array $schemaFields, array $values): array
    {
        $clean = [];
        foreach ($schemaFields as $def) {
            $k = $def['key'];
            $val = $values[$k] ?? null;
            $isEmpty = $val === null || $val === '' || $val === [];
            if ($def['required'] && $isEmpty) {
                throw new InvalidArgumentException("فیلد الزامی «{$def['label']}» ({$k}) وارد نشده است.");
            }
            if ($isEmpty) {
                $clean[$k] = null;
                continue;
            }
            $clean[$k] = match ($def['type']) {
                'number', 'price' => is_numeric($val) ? (float) $val : throw new InvalidArgumentException("مقدار فیلد «{$k}» باید عددی باشد."),
                'boolean' => (bool) $val,
                'email' => filter_var((string) $val, FILTER_VALIDATE_EMAIL) ? trim((string) $val) : throw new InvalidArgumentException("ایمیل فیلد «{$k}» معتبر نیست."),
                'url' => (filter_var((string) $val, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $val)) ? trim((string) $val) : throw new InvalidArgumentException("نشانی وب فیلد «{$k}» معتبر نیست."),
                'select' => ($def['options'] === [] || in_array((string) $val, $def['options'], true)) ? (string) $val : throw new InvalidArgumentException("گزینه انتخاب‌شده برای «{$k}» مجاز نیست."),
                'gallery', 'json' => is_array($val) ? $val : (json_decode((string) $val, true) ?? []),
                'relation' => is_int($val) || ctype_digit((string) $val) ? (int) $val : throw new InvalidArgumentException("شناسه ارتباطی «{$k}» معتبر نیست."),
                default => mb_substr(trim((string) $val), 0, 20000),
            };
        }
        return $clean;
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z][a-z0-9-]{1,62}$/', $key) !== 1) {
            throw new InvalidArgumentException('شناسه فنی نوع محتوا معتبر نیست: ' . $key);
        }
        return $key;
    }

    private static function slugify(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = (string) preg_replace('/[^a-z0-9\x{0600}-\x{06FF}\x{0400}-\x{04FF}-]+/u', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'item-' . bin2hex(random_bytes(4));
        }
        return mb_substr($slug, 0, 180);
    }

    private static function currentSiteId(): int
    {
        try {
            $id = SiteContext::id();
            return $id > 0 ? $id : 1;
        } catch (\Throwable) {
            return 1;
        }
    }
}
