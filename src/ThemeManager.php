<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;
use Throwable;

final class ThemeManager
{
    public static function templates(string $view): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $view) !== 1) {
            throw new RuntimeException('نام view معتبر نیست.');
        }
        $core = dirname(__DIR__) . '/views/public';
        $paths = [$core . '/head.php', $core . '/' . $view . '.php', $core . '/foot.php'];
        $allowed = [];
        try {
            $row = ExtensionManager::activeTheme();
            if (!$row) return ['paths' => $paths, 'allowed_roots' => $allowed];
            $manifest = ExtensionManager::manifestForRow($row);
            $errors = ExtensionManager::compatibilityErrors($manifest);
            if ($errors !== []) throw new RuntimeException(implode('؛ ', $errors));
            $views = $manifest->data()['views'];
            foreach (['head', $view, 'foot'] as $index => $slot) {
                if (isset($views[$slot])) $paths[$index] = $manifest->resolve($views[$slot]);
            }
            $allowed[] = $manifest->directory();
        } catch (Throwable $error) {
            if (isset($row['extension_key'])) {
                ExtensionManager::markBroken((string) $row['extension_key'], $error->getMessage());
            }
            error_log('[VazinCMS theme] ' . $error->getMessage());
        }
        return ['paths' => $paths, 'allowed_roots' => $allowed];
    }


    public static function hierarchy(string $context,array $data=[]): array
    {
        foreach(ThemeHierarchy::candidates($context,$data) as $candidate){
            $resolved=self::templates($candidate);
            if(is_file($resolved['paths'][1]))return $resolved;
        }
        return self::templates('index');
    }

    public static function previewTheme(?string $key=null): ?array
    {
        if($key===null||$key==='')return ExtensionManager::activeTheme();
        foreach(ExtensionManager::all() as $row){if(($row['extension_type']??'')==='theme'&&($row['extension_key']??'')===$key&&($row['status']??'')!=='broken')return $row;}
        return null;
    }

    public static function assetUrl(string $relative): string
    {
        $row = ExtensionManager::activeTheme();
        if (!$row) return '';
        self::validateAssetPath($relative);
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));
        return '/theme-assets/' . rawurlencode((string) $row['extension_key']) . '/' . $encoded;
    }

    public static function serveAsset(string $key, string $relative): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $key) !== 1) {
            self::notFound(); return;
        }
        self::validateAssetPath($relative);
        $row = ExtensionManager::activeTheme();
        if (!$row || !hash_equals((string) $row['extension_key'], $key)) {
            self::notFound(); return;
        }
        $manifest = ExtensionManager::manifestForRow($row);
        $assetRoot = realpath($manifest->directory() . '/assets');
        if ($assetRoot === false || !is_dir($assetRoot) || is_link($assetRoot)) {
            self::notFound(); return;
        }
        $cursor = $assetRoot;
        foreach (explode('/', $relative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) { self::notFound(); return; }
        }
        $file = realpath($cursor);
        if ($file === false || !is_file($file) || is_link($file)
            || !str_starts_with($file, $assetRoot . DIRECTORY_SEPARATOR)) {
            self::notFound(); return;
        }
        $types = [
            'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
            'mjs' => 'application/javascript; charset=utf-8', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        ];
        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if (!isset($types[$extension])) { self::notFound(); return; }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $types[$extension]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($file);
    }

    private static function validateAssetPath(string $relative): void
    {
        if ($relative === '' || strlen($relative) > 500 || str_contains($relative, '\\')
            || preg_match('#(^/|(^|/)\.\.?(/|$)|[^A-Za-z0-9._/-]|//)#', $relative) === 1) {
            throw new RuntimeException('مسیر asset قالب معتبر نیست.');
        }
    }

    private static function notFound(): void
    {
        http_response_code(404);
    }
}
