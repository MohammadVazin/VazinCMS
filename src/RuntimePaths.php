<?php
declare(strict_types=1);
namespace VazinCMS;

use RuntimeException;

/**
 * Resolves mutable CMS roots. Release code stays root-owned and immutable;
 * customer uploads and installed extensions live in the per-site runtime.
 */
final class RuntimePaths
{
    public static function storage(): string
    {
        return self::directory('VAZINCMS_STORAGE_PATH', dirname(__DIR__) . '/storage');
    }

    public static function uploads(): string
    {
        return self::directory('VAZINCMS_UPLOADS_PATH', dirname(__DIR__) . '/public/uploads');
    }

    public static function extensions(): string
    {
        $configured = getenv('VAZINCMS_EXTENSIONS_PATH');
        if (is_string($configured) && $configured !== '') {
            return self::directory('VAZINCMS_EXTENSIONS_PATH', $configured);
        }

        $storage = self::storage();
        $path = $storage . DIRECTORY_SEPARATOR . 'extensions';
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('CMS extensions directory is unavailable');
        }
        $resolved = self::directory('VAZINCMS_EXTENSIONS_PATH', $path);
        if ($resolved !== $storage && !str_starts_with($resolved, $storage . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('CMS extensions directory escaped storage');
        }
        return $resolved;
    }

    private static function directory(string $variable, string $fallback): string
    {
        $configured = getenv($variable);
        $path = is_string($configured) && $configured !== '' ? $configured : $fallback;
        if (strlen($path) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new RuntimeException('Invalid CMS runtime path');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('CMS runtime path is unavailable');
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }
}
