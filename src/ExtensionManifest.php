<?php
declare(strict_types=1);

namespace VazinCMS;

use JsonException;
use RuntimeException;

final class ExtensionManifest
{
    public const FILE = 'vazin-extension.json';
    public const TYPES = ['theme', 'module'];
    public const CATEGORIES = [
        'general', 'seo', 'commerce', 'lms', 'booking', 'forms',
        'publishing', 'security', 'integration', 'theme', 'migration',
    ];
    private const KEYS_V1 = [
        'schema', 'key', 'type', 'name', 'version', 'description', 'author',
        'requires', 'bootstrap', 'views', 'admin_navigation', 'default_status', 'protected',
    ];
    private const KEYS_V2 = [
        'schema', 'key', 'type', 'name', 'version', 'description', 'author', 'category',
        'requires', 'bootstrap', 'views', 'admin_navigation', 'default_status', 'protected',
        'permissions', 'outbound_hosts', 'site_scoped', 'content_types',
    ];

    private function __construct(private array $data, private string $directory)
    {
    }

    public static function fromDirectory(string $directory): self
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || is_link($root)) {
            throw new RuntimeException('مسیر افزونه معتبر نیست.');
        }

        $file = $root . DIRECTORY_SEPARATOR . self::FILE;
        if (!is_file($file) || is_link($file) || filesize($file) > 128 * 1024) {
            throw new RuntimeException('فایل manifest افزونه پیدا نشد یا معتبر نیست.');
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('ساختار JSON افزونه معتبر نیست.', 0, $error);
        }
        if (!is_array($data)) {
            throw new RuntimeException('manifest افزونه باید یک شیء JSON باشد.');
        }

        $schema = $data['schema'] ?? null;
        if (!in_array($schema, [1, 2], true)) {
            throw new RuntimeException('نسخهٔ schema افزونه پشتیبانی نمی‌شود.');
        }

        $allowedKeys = $schema === 2 ? self::KEYS_V2 : self::KEYS_V1;
        $unknown = array_diff(array_keys($data), $allowedKeys);
        if ($unknown !== []) {
            throw new RuntimeException('کلید ناشناخته در manifest: ' . implode(', ', $unknown));
        }

        $type = ($data['type'] ?? '') === 'plugin' ? 'module' : (string) ($data['type'] ?? '');
        $key = strtolower(trim((string) ($data['key'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $version = trim((string) ($data['version'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('نوع افزونه باید theme یا module باشد.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $key) !== 1) {
            throw new RuntimeException('شناسهٔ فنی افزونه معتبر نیست.');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new RuntimeException('نام افزونه معتبر نیست.');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new RuntimeException('نسخهٔ افزونه باید Semantic Version معتبر باشد.');
        }

        $requires = $data['requires'] ?? [];
        if (!is_array($requires)) {
            throw new RuntimeException('بخش requires افزونه معتبر نیست.');
        }
        $normalizedRequires = [
            'php' => self::constraint((string) ($requires['php'] ?? '>=8.2.0')),
            'vazincms' => self::constraint((string) ($requires['vazincms'] ?? '>=10.8.0')),
            'extensions' => [],
        ];
        $extensionRequirements = $requires['extensions'] ?? [];
        if (!is_array($extensionRequirements)) {
            throw new RuntimeException('وابستگی افزونه‌ها معتبر نیست.');
        }
        foreach ($extensionRequirements as $requiredKey => $constraint) {
            if (!is_string($requiredKey) || preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $requiredKey) !== 1) {
                throw new RuntimeException('شناسهٔ وابستگی افزونه معتبر نیست.');
            }
            $normalizedRequires['extensions'][$requiredKey] = self::constraint((string) $constraint);
        }

        $bootstrap = trim((string) ($data['bootstrap'] ?? ''));
        if ($bootstrap !== '') {
            if ($type !== 'module') {
                throw new RuntimeException('فقط ماژول می‌تواند bootstrap داشته باشد.');
            }
            self::relativePath($bootstrap, 'bootstrap');
            if (!str_ends_with(strtolower($bootstrap), '.php')) {
                throw new RuntimeException('bootstrap ماژول باید فایل PHP باشد.');
            }
        }

        $views = $data['views'] ?? [];
        if (!is_array($views) || ($type !== 'theme' && $views !== [])) {
            throw new RuntimeException('تعریف view فقط برای قالب مجاز است.');
        }
        $normalizedViews = [];
        foreach ($views as $view => $relative) {
            if (!is_string($view) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $view) !== 1) {
                throw new RuntimeException('نام view قالب معتبر نیست.');
            }
            $relative = (string) $relative;
            self::relativePath($relative, 'view');
            if (!str_ends_with(strtolower($relative), '.php')) {
                throw new RuntimeException('view قالب باید فایل PHP باشد.');
            }
            $normalizedViews[$view] = $relative;
        }

        $navigation = $data['admin_navigation'] ?? [];
        if (!is_array($navigation) || ($type !== 'module' && $navigation !== [])) {
            throw new RuntimeException('منوی مدیریت فقط برای ماژول مجاز است.');
        }
        $normalizedNavigation = [];
        foreach ($navigation as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['label', 'path', 'order']) !== []) {
                throw new RuntimeException('ساختار منوی مدیریت ماژول معتبر نیست.');
            }
            $label = trim((string) ($item['label'] ?? ''));
            $path = (string) ($item['path'] ?? '');
            $order = (int) ($item['order'] ?? 100);
            if (mb_strlen($label) < 1 || mb_strlen($label) > 80
                || preg_match('#^/admin/[a-z0-9][a-z0-9/-]{0,119}$#', $path) !== 1
                || str_contains($path, '//') || str_contains($path, '..') || $order < 0 || $order > 999) {
                throw new RuntimeException('مقدار منوی مدیریت ماژول معتبر نیست.');
            }
            $normalizedNavigation[] = ['label' => $label, 'path' => $path, 'order' => $order];
        }

        $status = (string) ($data['default_status'] ?? 'inactive');
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('وضعیت اولیه افزونه معتبر نیست.');
        }

        $normalized = [
            'schema' => $schema,
            'key' => $key,
            'type' => $type,
            'name' => $name,
            'version' => $version,
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 1000),
            'author' => mb_substr(trim((string) ($data['author'] ?? '')), 0, 160),
            'requires' => $normalizedRequires,
            'bootstrap' => $bootstrap,
            'views' => $normalizedViews,
            'admin_navigation' => $normalizedNavigation,
            'default_status' => $status,
            'protected' => (bool) ($data['protected'] ?? false),
        ];

        if ($schema === 2) {
            $category = strtolower(trim((string) ($data['category'] ?? ($type === 'theme' ? 'theme' : 'general'))));
            if (!in_array($category, self::CATEGORIES, true)) {
                throw new RuntimeException('دسته‌بندی افزونه در manifest معتبر نیست.');
            }
            $rawPerms = $data['permissions'] ?? [];
            if (!is_array($rawPerms) || ($type !== 'module' && $rawPerms !== [])) {
                throw new RuntimeException('بخش permissions فقط برای ماژول و به‌صورت آرایه مجاز است.');
            }
            $permissions = CapabilityGuard::validateList(array_values($rawPerms));

            $rawHosts = $data['outbound_hosts'] ?? [];
            if (!is_array($rawHosts)) {
                throw new RuntimeException('بخش outbound_hosts باید آرایه‌ای از دامنه‌ها باشد.');
            }
            $outboundHosts = [];
            foreach ($rawHosts as $h) {
                if (!is_string($h)) {
                    throw new RuntimeException('دامنه outbound_hosts معتبر نیست.');
                }
                $h = strtolower(trim($h));
                if (preg_match('/^[a-z0-9][a-z0-9.-]{1,190}\.[a-z]{2,24}$/', $h) !== 1 || str_contains($h, '..')) {
                    throw new RuntimeException('دامنه outbound_hosts معتبر نیست: ' . $h);
                }
                $outboundHosts[$h] = true;
            }
            if ($outboundHosts !== [] && !in_array('http:outbound', $permissions, true)) {
                throw new RuntimeException('تعریف outbound_hosts نیازمند مجوز http:outbound در permissions است.');
            }

            $contentTypes = $data['content_types'] ?? [];
            if (!is_array($contentTypes) || ($type !== 'module' && $contentTypes !== [])) {
                throw new RuntimeException('تعریف content_types معتبر نیست.');
            }
            if ($contentTypes !== [] && !in_array('content:types', $permissions, true)) {
                throw new RuntimeException('ثبت content_types نیازمند مجوز content:types در permissions است.');
            }

            $normalized['category'] = $category;
            $normalized['permissions'] = $permissions;
            $normalized['outbound_hosts'] = array_keys($outboundHosts);
            $normalized['site_scoped'] = (bool) ($data['site_scoped'] ?? false);
            $normalized['content_types'] = $contentTypes;
        }

        $manifest = new self($normalized, $root);
        if ($bootstrap !== '') {
            $manifest->resolve($bootstrap);
        }
        foreach ($normalizedViews as $relative) {
            $manifest->resolve($relative);
        }
        return $manifest;
    }

    public function schema(): int { return (int) ($this->data['schema'] ?? 1); }
    public function key(): string { return $this->data['key']; }
    public function type(): string { return $this->data['type']; }
    public function name(): string { return $this->data['name']; }
    public function version(): string { return $this->data['version']; }
    public function directory(): string { return $this->directory; }
    public function data(): array { return $this->data; }
    public function json(): string
    {
        return (string) json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function resolve(string $relative): string
    {
        self::relativePath($relative, 'path');
        $cursor = $this->directory;
        foreach (explode('/', $relative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('پیوند نمادین در بستهٔ افزونه مجاز نیست.');
            }
        }
        $resolved = realpath($cursor);
        if ($resolved === false || !is_file($resolved) || is_link($resolved)
            || !str_starts_with($resolved, $this->directory . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('فایل اعلام‌شدهٔ افزونه پیدا نشد.');
        }
        return $resolved;
    }

    public function compatibilityErrors(array $activeVersions = []): array
    {
        $errors = [];
        $requires = $this->data['requires'];
        if (!self::matches(PHP_VERSION, $requires['php'])) {
            $errors[] = 'PHP موردنیاز: ' . $requires['php'];
        }
        if (!self::matches(Version::current(), $requires['vazincms'])) {
            $errors[] = 'VazinCMS موردنیاز: ' . $requires['vazincms'];
        }
        foreach ($requires['extensions'] as $key => $constraint) {
            if (!isset($activeVersions[$key])) {
                $errors[] = 'وابستگی فعال نشده: ' . $key;
            } elseif (!self::matches((string) $activeVersions[$key], $constraint)) {
                $errors[] = 'نسخهٔ ناسازگار ' . $key . '؛ موردنیاز: ' . $constraint;
            }
        }
        return $errors;
    }

    public static function matches(string $version, string $constraint): bool
    {
        if ($constraint === '*') return true;
        if (preg_match('/^(>=|<=|>|<|=|\^|~)?(\d+\.\d+(?:\.\d+)?)$/', $constraint, $match) !== 1) {
            return false;
        }
        $operator = $match[1] !== '' ? $match[1] : '=';
        $target = self::normalizeVersion($match[2]);
        $version = self::normalizeVersion($version);
        if ($operator === '^') {
            $parts = array_map('intval', explode('.', $target));
            $upper = ($parts[0] > 0) ? ($parts[0] + 1) . '.0.0' : '0.' . ($parts[1] + 1) . '.0';
            return version_compare($version, $target, '>=') && version_compare($version, $upper, '<');
        }
        if ($operator === '~') {
            $parts = array_map('intval', explode('.', $target));
            $upper = $parts[0] . '.' . ($parts[1] + 1) . '.0';
            return version_compare($version, $target, '>=') && version_compare($version, $upper, '<');
        }
        return version_compare($version, $target, $operator === '=' ? '==' : $operator);
    }

    private static function constraint(string $constraint): string
    {
        $constraint = trim($constraint);
        if ($constraint === '*') return $constraint;
        if (preg_match('/^(?:>=|<=|>|<|=|\^|~)?\d+\.\d+(?:\.\d+)?$/', $constraint) !== 1) {
            throw new RuntimeException('قید نسخه در manifest معتبر نیست: ' . $constraint);
        }
        return $constraint;
    }

    private static function normalizeVersion(string $version): string
    {
        if (preg_match('/^(\d+)\.(\d+)$/', $version, $match) === 1) {
            return $match[1] . '.' . $match[2] . '.0';
        }
        return $version;
    }

    private static function relativePath(string $path, string $field): void
    {
        if ($path === '' || strlen($path) > 500 || str_contains($path, '\\')
            || preg_match('#(^/|(^|/)\.\.?(/|$)|[\x00-\x1f\x7f:]|//)#', $path) === 1) {
            throw new RuntimeException('مسیر ' . $field . ' معتبر نیست.');
        }
    }
}
