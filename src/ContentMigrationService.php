<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use SimpleXMLElement;

/** Parses untrusted CMS exports into a deliberately small, reviewable draft format. */
final class ContentMigrationService
{
    public const MAX_BYTES = 10_485_760;
    public const MAX_ITEMS = 500;
    private const LOCALES = ['fa','ar','en','ru','tr','hy','kk','tg','zh'];

    /** @return array{provider:string,items:list<array<string,mixed>>} */
    public static function preview(string $filename, string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('فایل خالی است یا از سقف ۱۰ مگابایت بزرگ‌تر است.');
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($extension) {
            'json' => self::fromJson($bytes),
            'xml' => self::fromXml($bytes),
            default => throw new InvalidArgumentException('فقط فایل XML یا JSON قابل انتقال است.'),
        };
    }

    /** @return array{provider:string,items:list<array<string,mixed>>} */
    private static function fromJson(string $bytes): array
    {
        try { $payload = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new InvalidArgumentException('ساختار JSON معتبر نیست.'); }
        if (is_array($payload['db'][0]['data']['posts'] ?? null)) return self::ghost($payload);
        if (is_array($payload['data'] ?? null) && isset($payload['data'][0]['attributes'])) return self::drupal($payload);
        if (!is_array($payload) || !is_array($payload['items'] ?? null)) throw new InvalidArgumentException('این JSON خروجی قابل‌حمل VazinCMS یا Ghost نیست.');
        $provider = (string)($payload['provider'] ?? 'vazin-export');
        if (!in_array($provider, ['vazin-export','vazincms'], true)) {
            throw new InvalidArgumentException('ارائه‌دهندهٔ JSON قابل شناسایی نیست.');
        }
        return ['provider' => 'vazin-export', 'items' => self::normalizeItems($payload['items'])];
    }

    /** @return array{provider:string,items:list<array<string,mixed>>} */
    private static function ghost(array $payload): array
    {
        $data = (array)$payload['db'][0]['data']; $tagNames = [];
        foreach ((array)($data['tags'] ?? []) as $tag) if (is_array($tag) && isset($tag['id'], $tag['name'])) $tagNames[(string)$tag['id']] = (string)$tag['name'];
        $items = [];
        foreach ((array)$data['posts'] as $post) {
            if (!is_array($post) || trim((string)($post['title'] ?? '')) === '') continue;
            $terms = [];
            foreach ((array)($post['tags'] ?? []) as $tagId) { $name = $tagNames[(string)$tagId] ?? ''; if ($name !== '') $terms[] = ['taxonomy'=>'tag','name'=>$name,'slug'=>'']; }
            $slug = (string)($post['slug'] ?? '');
            $items[] = ['source_ref'=>'ghost:'.(string)($post['id'] ?? count($items)),'title'=>(string)$post['title'],'slug'=>$slug,'body'=>(string)($post['html'] ?? $post['mobiledoc'] ?? ''),'content_type'=>'post','locale'=>'fa','source_path'=>$slug !== '' ? '/'.$slug.'/' : '','terms'=>$terms,'featured_source'=>(string)($post['feature_image'] ?? '')];
            if (count($items) >= self::MAX_ITEMS) break;
        }
        return ['provider'=>'ghost-json','items'=>self::normalizeItems($items)];
    }

    /** @return array{provider:string,items:list<array<string,mixed>>} */
    private static function drupal(array $payload): array
    {
        $items = [];
        foreach ((array)$payload['data'] as $node) {
            if (!is_array($node) || !str_starts_with((string)($node['type'] ?? ''), 'node--')) continue;
            $attributes = (array)($node['attributes'] ?? []); $title = (string)($attributes['title'] ?? '');
            if (trim($title) === '') continue;
            $body = $attributes['body'] ?? ''; if (is_array($body)) $body = (string)($body['value'] ?? '');
            $path = $attributes['path'] ?? []; if (is_array($path)) $path = (string)($path['alias'] ?? '');
            $image = $attributes['field_image'] ?? ''; if (is_array($image)) $image = (string)($image['uri']['url'] ?? $image['url'] ?? '');
            $slug = trim(basename((string)$path), '/');
            $items[] = ['source_ref'=>'drupal:'.(string)($node['id'] ?? count($items)),'title'=>$title,'slug'=>$slug,'body'=>$body,'content_type'=>'post','locale'=>'fa','source_path'=>$path,'terms'=>[],'featured_source'=>$image];
            if (count($items) >= self::MAX_ITEMS) break;
        }
        return ['provider'=>'drupal-jsonapi','items'=>self::normalizeItems($items)];
    }

    /** @return array{provider:string,items:list<array<string,mixed>>} */
    private static function fromXml(string $bytes): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            throw new InvalidArgumentException('فایل XML دارای تعریف ناامن است و پذیرفته نمی‌شود.');
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($bytes, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$xml instanceof SimpleXMLElement) throw new InvalidArgumentException('فایل XML معتبر نیست.');
        $wp = $xml->channel->item ?? null;
        if ($wp !== null) return ['provider' => 'wordpress-wxr', 'items' => self::wordpress($xml)];
        if (strtolower($xml->getName()) === 'j2xml' || isset($xml->content)) {
            return ['provider' => 'joomla-j2xml', 'items' => self::joomla($xml)];
        }
        throw new InvalidArgumentException('نوع XML شناخته نشد. برای جوملا از خروجی J2XML استفاده کنید.');
    }

    /** @return list<array<string,mixed>> */
    private static function wordpress(SimpleXMLElement $xml): array
    {
        $wp = 'http://wordpress.org/export/1.2/';
        $content = 'http://purl.org/rss/1.0/modules/content/';
        $items = [];
        foreach ($xml->channel->item as $node) {
            $w = $node->children($wp); $c = $node->children($content);
            $kind = (string)$w->post_type;
            if (!in_array($kind, ['post','page'], true)) continue;
            $terms = [];
            foreach ($node->category as $category) {
                $domain = (string)($category['domain'] ?? 'category');
                $name = self::plain((string)$category, 255);
                $slug = self::slug((string)($category['nicename'] ?? ''), $domain . ':' . $name);
                if ($name !== '') $terms[] = ['taxonomy' => $domain === 'post_tag' ? 'tag' : 'category', 'name' => $name, 'slug' => $slug];
            }
            $path = parse_url((string)$node->link, PHP_URL_PATH);
            $featuredSource = '';
            if (preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', (string)$c->encoded, $imageMatch)) $featuredSource = (string)$imageMatch[1];
            $items[] = [
                'source_ref' => 'wp:' . trim((string)$w->post_id),
                'title' => (string)$node->title,
                'slug' => (string)$w->post_name,
                'body' => (string)$c->encoded,
                'content_type' => $kind,
                'locale' => 'fa',
                'source_path' => is_string($path) ? $path : '',
                'terms' => $terms,
                'featured_source' => $featuredSource,
            ];
            if (count($items) >= self::MAX_ITEMS) break;
        }
        return self::normalizeItems($items);
    }

    /** @return list<array<string,mixed>> */
    private static function joomla(SimpleXMLElement $xml): array
    {
        $nodes = isset($xml->content) ? $xml->content->children() : $xml->children();
        $items = [];
        foreach ($nodes as $node) {
            $title = (string)($node->title ?? '');
            if (trim($title) === '') continue;
            $items[] = [
                'source_ref' => 'joomla:' . trim((string)($node->id ?? $node->alias ?? count($items))),
                'title' => $title,
                'slug' => (string)($node->alias ?? ''),
                'body' => (string)($node->fulltext ?? $node->introtext ?? ''),
                'content_type' => 'post',
                'locale' => 'fa',
            ];
            if (count($items) >= self::MAX_ITEMS) break;
        }
        return self::normalizeItems($items);
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private static function normalizeItems(array $items): array
    {
        $out = []; $seen = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || count($out) >= self::MAX_ITEMS) break;
            $title = self::plain((string)($item['title'] ?? ''), 255);
            if (self::length($title) < 2) continue;
            $ref = self::plain((string)($item['source_ref'] ?? ('item:' . $index)), 120);
            if ($ref === '' || isset($seen[$ref])) $ref = 'item:' . $index;
            $seen[$ref] = true;
            $locale = strtolower(trim((string)($item['locale'] ?? 'fa')));
            if (!in_array($locale, self::LOCALES, true)) $locale = 'fa';
            $out[] = [
                'source_ref' => $ref,
                'title' => $title,
                'slug' => self::slug((string)($item['slug'] ?? ''), $ref),
                'body' => self::plain((string)($item['body'] ?? ''), 100_000),
                'content_type' => (string)($item['content_type'] ?? '') === 'page' ? 'page' : 'post',
                'locale' => $locale,
                'source_path' => self::sourcePath((string)($item['source_path'] ?? '')),
                'terms' => self::terms($item['terms'] ?? []),
                'featured_source' => self::mediaSource((string)($item['featured_source'] ?? '')),
            ];
        }
        if ($out === []) throw new InvalidArgumentException('محتوای قابل انتقالی در فایل پیدا نشد.');
        return $out;
    }

    private static function plain(string $value, int $limit): string
    {
        $value = preg_replace('/<\s*(script|style|iframe|object|embed|form)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $value) ?? '';
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return self::cut($value, $limit);
    }

    private static function slug(string $candidate, string $ref): string
    {
        $candidate = strtolower(trim($candidate));
        $candidate = preg_replace('/[^a-z0-9]+/', '-', $candidate) ?? '';
        $candidate = trim($candidate, '-');
        if (!preg_match('/^[a-z0-9]/', $candidate)) $candidate = 'import-' . substr(hash('sha256', $ref), 0, 12);
        return substr($candidate, 0, 189);
    }

    private static function sourcePath(string $path): string
    {
        $path = trim($path);
        return preg_match('#^/[A-Za-z0-9/_\-.]{1,498}$#', $path) ? $path : '';
    }

    private static function mediaSource(string $source): string
    {
        $source = trim($source);
        if ($source === '' || strlen($source) > 1000 || preg_match('/[\x00-\x1f\x7f]/', $source)) return '';
        if (str_starts_with($source, '/') || filter_var($source, FILTER_VALIDATE_URL)) return $source;
        return '';
    }

    /** @return list<array{taxonomy:string,name:string,slug:string}> */
    private static function terms(mixed $terms): array
    {
        if (!is_array($terms)) return [];
        $out = []; $seen = [];
        foreach ($terms as $term) {
            if (!is_array($term) || count($out) >= 50) continue;
            $taxonomy = (string)($term['taxonomy'] ?? '') === 'tag' ? 'tag' : 'category';
            $name = self::plain((string)($term['name'] ?? ''), 255);
            if ($name === '') continue;
            $slug = self::slug((string)($term['slug'] ?? ''), $taxonomy . ':' . $name);
            $key = $taxonomy . ':' . $slug;
            if (isset($seen[$key])) continue;
            $seen[$key] = true; $out[] = compact('taxonomy','name','slug');
        }
        return $out;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function cut(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
