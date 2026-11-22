<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use Throwable;

/**
 * Extensible Visual Block Registry for BlockEditor & SiteTemplateBuilder.
 * Allows Schema v2 modules to register custom UI blocks with schema validation and fault-isolated SSR.
 */
final class BlockRegistry
{
    /**
     * @var array<string,array{
     *   module:string,
     *   label:string,
     *   category:string,
     *   schema:array<string,string>,
     *   renderer:callable
     * }>
     */
    private static array $blocks = [];

    /**
     * @param array{label?:string,category?:string,schema?:array<string,string>} $meta
     */
    public static function register(string $moduleKey, string $blockName, array $meta, callable $renderer): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $moduleKey) !== 1) {
            throw new InvalidArgumentException('Invalid module key for block registration.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,60}$/', $blockName) !== 1) {
            throw new InvalidArgumentException('نام بلوک ماژول معتبر نیست: ' . $blockName);
        }
        $fullType = $moduleKey . '/' . $blockName;
        self::$blocks[$fullType] = [
            'module' => $moduleKey,
            'label' => mb_substr(trim((string) ($meta['label'] ?? $blockName)), 0, 120),
            'category' => mb_substr(trim((string) ($meta['category'] ?? 'modules')), 0, 60),
            'schema' => is_array($meta['schema'] ?? null) ? $meta['schema'] : [],
            'renderer' => $renderer,
        ];
        return $fullType;
    }

    public static function has(string $type): bool
    {
        return isset(self::$blocks[$type]);
    }

    /**
     * @return array<string,array{module:string,label:string,category:string,schema:array<string,string>}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::$blocks as $type => $def) {
            $out[$type] = [
                'module' => $def['module'],
                'label' => $def['label'],
                'category' => $def['category'],
                'schema' => $def['schema'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function validateData(string $type, array $data): void
    {
        if (!isset(self::$blocks[$type])) {
            throw new InvalidArgumentException('بلوک ماژول ثبت نشده است: ' . $type);
        }
        $schema = self::$blocks[$type]['schema'];
        foreach ($schema as $field => $rule) {
            $required = str_contains($rule, 'required');
            if ($required && (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null)) {
                throw new InvalidArgumentException("فیلد الزامی بلوک مقداردهی نشده است: {$field}");
            }
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }
            $base = explode(':', $rule)[0];
            $val = $data[$field];
            $valid = match ($base) {
                'string' => is_string($val) && mb_strlen($val) <= 5000,
                'int'    => is_int($val) || (is_string($val) && preg_match('/^-?\d+$/', $val) === 1),
                'number' => is_int($val) || is_float($val) || is_numeric($val),
                'bool'   => is_bool($val),
                'array'  => is_array($val) && count($val) <= 200,
                default  => true,
            };
            if (!$valid) {
                throw new InvalidArgumentException("نوع داده فیلد «{$field}» در بلوک «{$type}» معتبر نیست.");
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $type, array $data): string
    {
        if (!isset(self::$blocks[$type])) {
            return '';
        }
        $def = self::$blocks[$type];
        try {
            $html = (string) ($def['renderer'])($data);
            // Strip dangerous inline script/event handlers from module block HTML output
            $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
            $html = (string) preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
            return '<div class="vz-module-block" data-block-type="'
                . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">' . $html . '</div>';
        } catch (Throwable $error) {
            error_log('[VazinCMS block=' . $type . ' module=' . $def['module'] . '] ' . $error->getMessage());
            return '<!-- module block render isolated -->';
        }
    }

    public static function reset(): void
    {
        self::$blocks = [];
    }
}
