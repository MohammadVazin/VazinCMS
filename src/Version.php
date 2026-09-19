<?php
declare(strict_types=1);
namespace VazinCMS;

final class Version
{
    private static ?string $value = null;

    public static function current(): string
    {
        if (self::$value !== null) return self::$value;
        $value = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION'));
        return self::$value = preg_match('/^\d+\.\d+\.\d+$/', $value) ? $value : 'unknown';
    }
}
