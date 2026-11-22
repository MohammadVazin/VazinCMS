<?php
declare(strict_types=1);
namespace VazinCMS;

use InvalidArgumentException;

final class BlockEditor
{
    public const SCHEMA = 1;
    private const TYPES = [
        'paragraph', 'heading', 'list', 'image', 'gallery',
        'button', 'embed', 'code', 'columns', 'reusable',
        'hero', 'features', 'pricing', 'faq', 'testimonial', 'cta', 'stats', 'callout',
    ];

    public static function decode(string $json): array
    {
        if (trim($json) === '') return ['schema' => self::SCHEMA, 'blocks' => []];
        $doc = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || ($doc['schema'] ?? null) !== self::SCHEMA || !is_array($doc['blocks'] ?? null)) {
            throw new InvalidArgumentException('Block document is invalid.');
        }
        foreach ($doc['blocks'] as $block) self::validateBlock($block);
        return $doc;
    }

    public static function encode(array $doc): string
    {
        self::decode(json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function render(array $doc): string
    {
        $out = '';
        foreach ($doc['blocks'] ?? [] as $block) {
            if (is_array($block)) {
                $out .= self::renderBlock($block);
            }
        }
        return class_exists(ExtensionRuntime::class)
            ? ExtensionRuntime::filter('content.blocks_html', $out, ['document' => $doc])
            : $out;
    }

    public static function legacy(string $body): array
    {
        $blocks = [];
        foreach (preg_split('/\R{2,}/u', trim($body)) ?: [] as $chunk) {
            $text = trim($chunk);
            if ($text === '') continue;
            $blocks[] = ['type' => 'paragraph', 'data' => ['text' => $text]];
        }
        return ['schema' => self::SCHEMA, 'blocks' => $blocks];
    }

    private static function validateBlock(mixed $block): void
    {
        if (!is_array($block) || !is_string($block['type'] ?? null) || !is_array($block['data'] ?? null)) {
            throw new InvalidArgumentException('Unsupported block.');
        }
        $type = $block['type'];
        if (!in_array($type, self::TYPES, true)) {
            if (str_contains($type, '/') && BlockRegistry::has($type)) {
                BlockRegistry::validateData($type, $block['data']);
                return;
            }
            throw new InvalidArgumentException('Unsupported block: ' . $type);
        }
        if ($type === 'gallery') {
            $images = $block['data']['images'] ?? [];
            if (!is_array($images) || !array_is_list($images) || count($images) > 100) {
                throw new InvalidArgumentException('Gallery image list is invalid.');
            }
            if (isset($block['data']['columns']) && !in_array($block['data']['columns'], [2, 3, 4], true)) {
                throw new InvalidArgumentException('Gallery column count is invalid.');
            }
            foreach ($images as $image) {
                if (!is_array($image)) throw new InvalidArgumentException('Gallery image is invalid.');
                foreach (['src' => 2048, 'alt' => 255, 'caption' => 500] as $key => $max) {
                    if (isset($image[$key]) && (!is_string($image[$key]) || mb_strlen($image[$key]) > $max)) {
                        throw new InvalidArgumentException('Gallery metadata is invalid.');
                    }
                }
            }
        }
        if ($type === 'columns') {
            foreach (($block['data']['columns'] ?? []) as $column) {
                foreach (($column['blocks'] ?? []) as $child) {
                    self::validateBlock($child);
                }
            }
        }
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function renderBlock(array $b): string
    {
        $t = (string) ($b['type'] ?? '');
        $d = is_array($b['data'] ?? null) ? $b['data'] : [];
        if (!in_array($t, self::TYPES, true) && str_contains($t, '/')) {
            return BlockRegistry::render($t, $d);
        }
        return match ($t) {
            'paragraph'   => '<p>' . self::esc((string) ($d['text'] ?? '')) . '</p>',
            'heading'     => '<h' . max(2, min(6, (int) ($d['level'] ?? 2))) . '>' . self::esc((string) ($d['text'] ?? '')) . '</h' . max(2, min(6, (int) ($d['level'] ?? 2))) . '>',
            'list'        => self::renderList($d),
            'image'       => self::renderImage($d),
            'gallery'     => self::renderGallery($d),
            'button'      => self::renderButton($d),
            'embed'       => self::renderEmbed($d),
            'code'        => '<pre><code>' . self::esc((string) ($d['code'] ?? '')) . '</code></pre>',
            'columns'     => self::renderColumns($d),
            'reusable'    => '<div data-reusable="' . self::esc((string) ($d['key'] ?? '')) . '"></div>',
            'hero'        => self::renderHero($d),
            'features'    => self::renderFeatures($d),
            'pricing'     => self::renderPricing($d),
            'faq'         => self::renderFaq($d),
            'testimonial' => self::renderTestimonial($d),
            'cta'         => self::renderCta($d),
            'stats'       => self::renderStats($d),
            'callout'     => '<aside class="cms-callout cms-callout--' . self::esc((string) ($d['tone'] ?? 'info')) . '"><strong>' . self::esc((string) ($d['title'] ?? '')) . '</strong><p>' . self::esc((string) ($d['text'] ?? '')) . '</p></aside>',
            default       => '',
        };
    }

    private static function renderList(array $d): string
    {
        $tag = !empty($d['ordered']) ? 'ol' : 'ul';
        $items = '';
        foreach ((array) ($d['items'] ?? []) as $x) $items .= '<li>' . self::esc((string) $x) . '</li>';
        return '<' . $tag . '>' . $items . '</' . $tag . '>';
    }

    private static function safeUrl(string $u): string
    {
        if (str_starts_with($u, '//') || str_contains($u, '\\') || preg_match('/[\x00-\x1F\x7F]/', $u)) return '';
        return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u) ? $u : (str_starts_with($u, '/') ? $u : '');
    }

    private static function renderImage(array $d): string
    {
        $src = self::safeUrl((string) ($d['src'] ?? ''));
        return $src === '' ? '' : '<figure><img src="' . self::esc($src) . '" alt="' . self::esc((string) ($d['alt'] ?? '')) . '" loading="lazy"></figure>';
    }

    private static function renderGallery(array $d): string
    {
        $columns = in_array(($d['columns'] ?? 3), [2, 3, 4], true) ? (int) ($d['columns'] ?? 3) : 3;
        $out = '<div class="cms-gallery cms-gallery-columns-' . $columns . '">';
        foreach (array_slice((array) ($d['images'] ?? []), 0, 100) as $image) {
            if (!is_array($image)) continue;
            $raw = (string) ($image['src'] ?? '');
            $src = self::safeUrl($raw);
            if ($src === '') continue;
            $out .= '<figure><img src="' . self::esc($src) . '" alt="' . self::esc((string) ($image['alt'] ?? '')) . '" loading="lazy">';
            $caption = (string) ($image['caption'] ?? '');
            if ($caption !== '') $out .= '<figcaption>' . self::esc($caption) . '</figcaption>';
            $out .= '</figure>';
        }
        return $out . '</div>';
    }

    private static function renderButton(array $d): string
    {
        $url = self::safeUrl((string) ($d['url'] ?? ''));
        return $url === '' ? '' : '<p class="cms-button"><a href="' . self::esc($url) . '">' . self::esc((string) ($d['label'] ?? '')) . '</a></p>';
    }

    private static function renderEmbed(array $d): string
    {
        $url = self::safeUrl((string) ($d['url'] ?? ''));
        return $url === '' ? '' : '<p class="cms-embed"><a href="' . self::esc($url) . '" rel="noopener noreferrer">' . self::esc($url) . '</a></p>';
    }

    private static function renderColumns(array $d): string
    {
        $out = '<div class="cms-columns">';
        foreach ((array) ($d['columns'] ?? []) as $col) {
            $out .= '<div class="cms-column">';
            foreach ((array) ($col['blocks'] ?? []) as $child) $out .= self::renderBlock((array) $child);
            $out .= '</div>';
        }
        return $out . '</div>';
    }

    private static function renderHero(array $d): string
    {
        $badge = trim((string) ($d['badge'] ?? ''));
        $title = self::esc((string) ($d['title'] ?? ''));
        $subtitle = self::esc((string) ($d['subtitle'] ?? ''));
        $ctaUrl = self::safeUrl((string) ($d['cta_url'] ?? ''));
        $ctaLabel = self::esc((string) ($d['cta_label'] ?? ''));
        $out = '<section class="cms-hero">';
        if ($badge !== '') $out .= '<span class="cms-hero-badge">' . self::esc($badge) . '</span>';
        $out .= '<h2>' . $title . '</h2>';
        if ($subtitle !== '') $out .= '<p>' . $subtitle . '</p>';
        if ($ctaUrl !== '' && $ctaLabel !== '') $out .= '<a class="cms-hero-cta" href="' . self::esc($ctaUrl) . '">' . $ctaLabel . '</a>';
        return $out . '</section>';
    }

    private static function renderFeatures(array $d): string
    {
        $out = '<section class="cms-features">';
        foreach (array_slice((array) ($d['items'] ?? []), 0, 24) as $item) {
            if (!is_array($item)) continue;
            $out .= '<article class="cms-feature-card"><h3>' . self::esc((string) ($item['title'] ?? '')) . '</h3><p>' . self::esc((string) ($item['description'] ?? '')) . '</p></article>';
        }
        return $out . '</section>';
    }

    private static function renderPricing(array $d): string
    {
        $out = '<section class="cms-pricing">';
        foreach (array_slice((array) ($d['plans'] ?? []), 0, 12) as $plan) {
            if (!is_array($plan)) continue;
            $featured = !empty($plan['featured']) ? ' is-featured' : '';
            $out .= '<article class="cms-pricing-card' . $featured . '"><h3>' . self::esc((string) ($plan['name'] ?? '')) . '</h3><div class="cms-price">' . self::esc((string) ($plan['price'] ?? '')) . '</div>';
            if (is_array($plan['features'] ?? null)) {
                $out .= '<ul>';
                foreach ($plan['features'] as $f) $out .= '<li>' . self::esc((string) $f) . '</li>';
                $out .= '</ul>';
            }
            $url = self::safeUrl((string) ($plan['cta_url'] ?? ''));
            if ($url !== '') $out .= '<a href="' . self::esc($url) . '">' . self::esc((string) ($plan['cta_label'] ?? 'انتخاب')) . '</a>';
            $out .= '</article>';
        }
        return $out . '</section>';
    }

    private static function renderFaq(array $d): string
    {
        $out = '<section class="cms-faq">';
        foreach (array_slice((array) ($d['items'] ?? []), 0, 50) as $qa) {
            if (!is_array($qa)) continue;
            $out .= '<details class="cms-faq-item"><summary>' . self::esc((string) ($qa['question'] ?? '')) . '</summary><p>' . self::esc((string) ($qa['answer'] ?? '')) . '</p></details>';
        }
        return $out . '</section>';
    }

    private static function renderTestimonial(array $d): string
    {
        return '<blockquote class="cms-testimonial"><p>' . self::esc((string) ($d['quote'] ?? '')) . '</p><cite>' . self::esc((string) ($d['author'] ?? '')) . '</cite></blockquote>';
    }

    private static function renderCta(array $d): string
    {
        $url = self::safeUrl((string) ($d['url'] ?? ''));
        $btn = $url !== '' ? '<a class="cms-cta-btn" href="' . self::esc($url) . '">' . self::esc((string) ($d['button'] ?? 'ادامه')) . '</a>' : '';
        return '<section class="cms-cta-banner"><h3>' . self::esc((string) ($d['title'] ?? '')) . '</h3><p>' . self::esc((string) ($d['text'] ?? '')) . '</p>' . $btn . '</section>';
    }

    private static function renderStats(array $d): string
    {
        $out = '<section class="cms-stats">';
        foreach (array_slice((array) ($d['items'] ?? []), 0, 12) as $stat) {
            if (!is_array($stat)) continue;
            $out .= '<div class="cms-stat"><strong>' . self::esc((string) ($stat['value'] ?? '')) . '</strong><span>' . self::esc((string) ($stat['label'] ?? '')) . '</span></div>';
        }
        return $out . '</section>';
    }
}
