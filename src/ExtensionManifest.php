<?php
declare(strict_types=1);

namespace VazinCMS;

use JsonException;
use RuntimeException;

final class ExtensionManifest
{
    public const FILE = 'vazin-extension.json';
    public const TYPES = ['theme', 'module'];
    private const KEYS = [
        'schema', 'key', 'type', 'name', 'version', 'description', 'author',
        'requires', 'bootstrap', 'views', 'admin_navigation', 'default_status', 'protected',
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

        $unknown = array_diff(array_keys($data), self::KEYS);
        if ($unknown !== []) {
            throw new RuntimeException('کلید ناشناخته در manifest: ' . implode(', ', $unknown));
        }
        if (($data['schema'] ?? null) !== 1) {
            throw new RuntimeException('نسخهٔ schema افزونه پشتیبانی نمی‌شود.');
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
            if (!is_array($item) || array_diff(array_keys($item), ['label','path','order']) !== []) {
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
            'schema' => 1,
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

        $manifest = new self($normalized, $root);
        if ($bootstrap !== '') {
            $manifest->resolve($bootstrap);
        }
        foreach ($normalizedViews as $relative) {
            $manifest->resolve($relative);
        }
        return $manifest;
    }

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
