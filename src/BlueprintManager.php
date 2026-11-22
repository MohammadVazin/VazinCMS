<?php
declare(strict_types=1);

namespace VazinCMS;

use InvalidArgumentException;
use PDO;

/**
 * Universal Site Blueprints Engine.
 * Configures VazinCMS in 1 click for ANY website category (Corporate, E-Commerce, Magazine, LMS, Booking, Portfolio, Blog).
 */
final class BlueprintManager
{
    public static function catalog(): array
    {
        $builtIn = [
            'corporate' => [
                'key' => 'corporate',
                'name' => 'شرکتی، سازمانی و خدماتی',
                'name_en' => 'Corporate & Business',
                'description' => 'مناسب برای شرکت‌ها، استارتاپ‌ها، آژانس‌ها و معرفی خدمات با صفحه‌ساز بصری، فرم تماس و سئوی کامل.',
                'modules' => ['seo', 'vazin-forms'],
                'content_types' => [
                    'service' => [
                        'label' => 'خدمت تخصصی',
                        'plural_label' => 'خدمات',
                        'fields' => [
                            ['key' => 'icon', 'label' => 'آیکون', 'type' => 'text', 'required' => false],
                            ['key' => 'Starting_price', 'label' => 'تعرفه پایه', 'type' => 'text', 'required' => false],
                        ],
                    ],
                ],
                'starter_pages' => [
                    [
                        'slug' => 'home',
                        'title' => 'صفحه اصلی سازمان',
                        'is_home' => 1,
                        'blocks' => [
                            ['type' => 'hero', 'data' => ['badge' => 'نسل جدید وب', 'title' => 'راهکارهای حرفه‌ای برای رشد کسب‌وکار شما', 'subtitle' => 'سریع، امن و مقیاس‌پذیر با معماری ماژولار VazinCMS.', 'cta_label' => 'تماس با ما', 'cta_url' => '/fa/page/contact']],
                            ['type' => 'features', 'data' => ['items' => [
                                ['title' => 'سرعت فوق‌العاده', 'description' => 'کش لبه داخلی و هسته بهینه بدون افزونه‌های سنگین.'],
                                ['title' => 'امنیت Zero-Trust', 'description' => 'ایزولاسیون کامل ماژول‌ها و حفاظت از داده‌ها.'],
                                ['title' => 'چندزبانگی بومی', 'description' => 'پشتیبانی هم‌زمان از فارسی، انگلیسی، عربی و روسی.'],
                            ]]],
                        ],
                    ],
                    [
                        'slug' => 'contact',
                        'title' => 'تماس با ما',
                        'is_home' => 0,
                        'blocks' => [
                            ['type' => 'heading', 'data' => ['level' => 2, 'text' => 'ارتباط با تیم ما']],
                            ['type' => 'paragraph', 'data' => ['text' => 'از طریق فرم زیر پیام خود را برای ما ارسال کنید.']],
                        ],
                    ],
                ],
                'menus' => [
                    ['label' => 'صفحه اصلی', 'url' => '/fa', 'position' => 1],
                    ['label' => 'تماس با ما', 'url' => '/fa/page/contact', 'position' => 2],
                ],
            ],
            'ecommerce' => [
                'key' => 'ecommerce',
                'name' => 'فروشگاه اینترنتی (کالا و دیجیتال)',
                'name_en' => 'E-Commerce Storefront',
                'description' => 'فروشگاه کامل با کاتالوگ محصولات، مدیریت موجودی، سبد خرید، سفارش‌ها و درگاه‌های پرداخت پلاگین‌پذیر.',
                'modules' => ['seo', 'vazin-commerce', 'vazin-forms'],
                'content_types' => [],
                'starter_pages' => [
                    [
                        'slug' => 'home',
                        'title' => 'فروشگاه آنلاین',
                        'is_home' => 1,
                        'blocks' => [
                            ['type' => 'hero', 'data' => ['badge' => 'فروشگاه رسمی', 'title' => 'خرید آنلاین سریع و مطمئن', 'subtitle' => 'محصولات منتخب با تحویل فوری و ضمانت اصالت.', 'cta_label' => 'مشاهده محصولات', 'cta_url' => '/shop']],
                        ],
                    ],
                ],
                'menus' => [
                    ['label' => 'خانه', 'url' => '/fa', 'position' => 1],
                    ['label' => 'فروشگاه', 'url' => '/shop', 'position' => 2],
                    ['label' => 'سبد خرید', 'url' => '/shop/cart', 'position' => 3],
                ],
            ],
            'magazine' => [
                'key' => 'magazine',
                'name' => 'خبری، رسانه و مجله اینترنتی',
                'name_en' => 'News & Digital Magazine',
                'description' => 'پرتال خبری و تحریریه چندنویسنده‌ای با دسته‌بندی موضوعی، آرشیو سریع، فیدهای استاندارد و انتشار خودکار.',
                'modules' => ['seo', 'publishing', 'vazin-forms'],
                'content_types' => [
                    'news' => [
                        'label' => 'خبر فوری',
                        'plural_label' => 'اخبار',
                        'fields' => [
                            ['key' => 'source_name', 'label' => 'منبع خبر', 'type' => 'text', 'required' => false],
                            ['key' => 'reading_minutes', 'label' => 'زمان مطالعه (دقیقه)', 'type' => 'number', 'required' => false],
                        ],
                    ],
                ],
                'starter_pages' => [
                    [
                        'slug' => 'home',
                        'title' => 'پایگاه خبری و تحلیلی',
                        'is_home' => 1,
                        'blocks' => [
                            ['type' => 'hero', 'data' => ['badge' => 'تازه‌ترین گزارش‌ها', 'title' => 'رسانه مستقل و سریع شما', 'subtitle' => 'پوشش دقیق رویدادها، مقالات تحلیلی و پرونده‌های ویژه.', 'cta_label' => 'آرشیو مطالب', 'cta_url' => '/fa/archive']],
                        ],
                    ],
                ],
                'menus' => [
                    ['label' => 'صفحه اول', 'url' => '/fa', 'position' => 1],
                    ['label' => 'آرشیو مقالات', 'url' => '/fa/archive', 'position' => 2],
                ],
            ],
            'academy' => [
                'key' => 'academy',
                'name' => 'آموزشگاه آنلاین و دوره‌ها (LMS)',
                'name_en' => 'Online Academy & LMS',
                'description' => 'پلتفرم آموزش آنلاین با مدیریت دوره‌ها، سرفصل‌ها، دروس، ثبت‌نام دانشجویان و پیگیری پیشرفت تحصیلی.',
                'modules' => ['seo', 'vazin-lms', 'vazin-forms'],
                'content_types' => [],
                'starter_pages' => [
                    [
                        'slug' => 'home',
                        'title' => 'آکادمی آموزش تخصصی',
                        'is_home' => 1,
                        'blocks' => [
                            ['type' => 'hero', 'data' => ['badge' => 'یادگیری مهارت‌محور', 'title' => 'مسیر یادگیری حرفه‌ای خود را آغاز کنید', 'subtitle' => 'دوره‌های کاربردی، پروژه‌محور و گواهی پایان دوره.', 'cta_label' => 'مشاهده دوره‌ها', 'cta_url' => '/courses']],
                        ],
                    ],
                ],
                'menus' => [
                    ['label' => 'صفحه اصلی', 'url' => '/fa', 'position' => 1],
                    ['label' => 'دوره‌های آموزشی', 'url' => '/courses', 'position' => 2],
                ],
            ],
            'booking' => [
                'key' => 'booking',
                'name' => 'نوبت‌دهی، کلینیک و رزرواسیون خدمات',
                'name_en' => 'Appointments & Service Booking',
                'description' => 'سامانه رزرو آنلاین وقت مشاوره، کلینیک، سالن‌ها و خدمات تخصصی با مدیریت ظرفیت و زمان‌بندی.',
                'modules' => ['seo', 'vazin-booking', 'vazin-forms'],
                'content_types' => [],
                'starter_pages' => [
                    [
                        'slug' => 'home',
                        'title' => 'سامانه رزرو آنلاین نوبت',
                        'is_home' => 1,
                        'blocks' => [
                            ['type' => 'hero', 'data' => ['badge' => 'رزرو آنی', 'title' => 'نوبت خود را در چند ثانیه آنلاین رزرو کنید', 'subtitle' => 'انتخاب خدمت، مشاهده زمان‌های آزاد و دریافت کد پیگیری.', 'cta_label' => 'رزرو نوبت', 'cta_url' => '/booking']],
                        ],
                    ],
                ],
                'menus' => [
                    ['label' => 'خانه', 'url' => '/fa', 'position' => 1],
                    ['label' => 'رزرو آنلاین', 'url' => '/booking', 'position' => 2],
                ],
            ],
            'portfolio' => [
                'key' => 'portfolio',
                'name' => 'نمونه‌کار، شخصی و استودیو خلاقیت',
                'name_en' => 'Portfolio & Creative Studio',
                'description' => 'نمایش حرفه‌ای پروژه‌ها، رزومه، گالری آثار و دریافت سفارش پروژه.',
                'modules' => ['seo', 'vazin-forms'],
                'content_types' => [
                    'project' => [
                        'label' => 'پروژه و نمونه‌کار',
                        'plural_label' => 'نمونه‌کارها',
                        'fields' => [
                            ['key' => 'client', 'label' => 'کارفرما', 'type' => 'text', 'required' => false],
                            ['key' => 'project_url', 'label' => 'لینک پروژه', 'type' => 'url', 'required' => false],
                            ['key' => 'year', 'label' => 'سال اجرا', 'type' => 'number', 'required' => false],
                        ],
                    ],
                ],
                'starter_pages' => [
                    [
                        'slug' => 'home',
                        'title' => 'استودیو طراحی و توسعه',
                        'is_home' => 1,
                        'blocks' => [
                            ['type' => 'hero', 'data' => ['badge' => 'پورتفولیو', 'title' => 'خلق تجربه‌های دیجیتال ماندگار', 'subtitle' => 'گزیده‌ای از پروژه‌ها و دستاوردهای اجراشده.', 'cta_label' => 'ثبت سفارش پروژه', 'cta_url' => '/fa/page/contact']],
                        ],
                    ],
                ],
                'menus' => [
                    ['label' => 'معرفی', 'url' => '/fa', 'position' => 1],
                ],
            ],
            'blog' => [
                'key' => 'blog',
                'name' => 'وبلاگ شخصی و یادداشت‌های تخصصی',
                'name_en' => 'Personal & Tech Blog',
                'description' => 'چیدمان مینیمال، خوانا و فوق‌سریع برای نویسندگان، توسعه‌دهندگان و تولیدکنندگان محتوا.',
                'modules' => ['seo', 'publishing'],
                'content_types' => [],
                'starter_pages' => [
                    [
                        'slug' => 'home',
                        'title' => 'یادداشت‌ها و مقالات',
                        'is_home' => 1,
                        'blocks' => [
                            ['type' => 'hero', 'data' => ['badge' => 'وبلاگ', 'title' => 'دیدگاه‌ها، تجربه‌ها و آموخته‌ها', 'subtitle' => 'انتشار منظم یادداشت‌های تخصصی با حداکثر سرعت بارگذاری.', 'cta_label' => 'همه نوشته‌ها', 'cta_url' => '/fa/archive']],
                        ],
                    ],
                ],
                'menus' => [
                    ['label' => 'خانه', 'url' => '/fa', 'position' => 1],
                    ['label' => 'نوشته‌ها', 'url' => '/fa/archive', 'position' => 2],
                ],
            ],
        ];

        return ExtensionRuntime::filter('site.blueprints', $builtIn);
    }

    /**
     * @return list<string>
     */
    public static function validProfiles(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Applies a Site Blueprint: activates required bundled modules, defines custom content types,
     * seeds starter pages (if missing) and menu items, and saves `site_profile` setting.
     *
     * @return array{blueprint:string,activated_modules:list<string>,created_types:list<string>,seeded_pages:int}
     */
    public static function apply(PDO $pdo, string $blueprintKey, string $locale = 'fa', ?int $actorId = null): array
    {
        $catalog = self::catalog();
        $blueprintKey = strtolower(trim($blueprintKey));
        if (!isset($catalog[$blueprintKey])) {
            throw new InvalidArgumentException('کیت سایت (Blueprint) معتبر نیست: ' . $blueprintKey);
        }
        $bp = $catalog[$blueprintKey];
        $scoped = false;
        try {
            $scoped = SiteScopeMode::active($pdo);
        } catch (\Throwable) {
        }
        $siteId = $scoped ? SiteContext::id() : 1;

        $activated = [];
        if (!$scoped) {
            try {
                ExtensionManager::synchronize(true);
                foreach ($bp['modules'] as $modKey) {
                    if (!ExtensionManager::isActive('module', $modKey)) {
                        try {
                            ExtensionManager::activate($modKey);
                            $activated[] = $modKey;
                        } catch (\Throwable) {
                        }
                    } else {
                        $activated[] = $modKey;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $createdTypes = [];
        foreach ($bp['content_types'] as $typeKey => $rawDef) {
            $normFields = [];
            foreach ($rawDef['fields'] ?? [] as $f) {
                $f['key'] = strtolower((string) $f['key']);
                $normFields[] = $f;
            }
            $rawDef['fields'] = $normFields;
            DynamicContentService::defineType($pdo, (string) $typeKey, $rawDef, $siteId);
            $createdTypes[] = (string) $typeKey;
        }

        $seededPages = 0;
        foreach ($bp['starter_pages'] as $pageSpec) {
            $slug = (string) $pageSpec['slug'];
            if ($scoped) {
                $chk = $pdo->prepare('SELECT id FROM cms_pages WHERE site_id=:s AND locale=:l AND slug=:slug LIMIT 1');
                $chk->execute(['s' => $siteId, 'l' => $locale, 'slug' => $slug]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM cms_pages WHERE locale=:l AND slug=:slug LIMIT 1');
                $chk->execute(['l' => $locale, 'slug' => $slug]);
            }
            if ($chk->fetchColumn()) {
                continue;
            }
            $doc = ['schema' => BlockEditor::SCHEMA, 'blocks' => $pageSpec['blocks'] ?? []];
            $blocksJson = BlockEditor::encode($doc);
            $renderedBody = BlockEditor::render($doc);
            try {
                if ($scoped) {
                    $ins = $pdo->prepare("INSERT INTO cms_pages(site_id, locale, slug, title, body, content_type, status, is_home, author_id, published_at) VALUES(:s, :l, :slug, :title, :body, 'page', 'published', :home, :author, CURRENT_TIMESTAMP)");
                    $ins->execute([
                        's' => $siteId, 'l' => $locale, 'slug' => $slug,
                        'title' => (string) $pageSpec['title'], 'body' => $renderedBody,
                        'home' => (int) ($pageSpec['is_home'] ?? 0), 'author' => $actorId,
                    ]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO cms_pages(locale, slug, title, body, content_type, status, is_home, author_id, published_at) VALUES(:l, :slug, :title, :body, 'page', 'published', :home, :author, CURRENT_TIMESTAMP)");
                    $ins->execute([
                        'l' => $locale, 'slug' => $slug,
                        'title' => (string) $pageSpec['title'], 'body' => $renderedBody,
                        'home' => (int) ($pageSpec['is_home'] ?? 0), 'author' => $actorId,
                    ]);
                }
                $pageId = (int) $pdo->lastInsertId();
                if ($pageId > 0 && ($pageSpec['blocks'] ?? []) !== []) {
                    try {
                        if ($scoped) {
                            (new SiteScopedBlockRepository($pdo, $siteId))->savePageBlocks($pageId, $doc, $actorId ?? 1);
                        } else {
                            BlockRepository::save($pdo, $pageId, $blocksJson);
                        }
                    } catch (\Throwable) {
                    }
                }
                $seededPages++;
            } catch (\Throwable) {
            }
        }

        foreach ($bp['menus'] ?? [] as $menuItem) {
            try {
                if ($scoped) {
                    $exists = $pdo->prepare('SELECT 1 FROM cms_menu_items WHERE site_id=:s AND locale=:l AND url=:u LIMIT 1');
                    $exists->execute(['s' => $siteId, 'l' => $locale, 'u' => $menuItem['url']]);
                    if (!$exists->fetchColumn()) {
                        $pdo->prepare('INSERT INTO cms_menu_items(site_id, locale, label, url, position, is_enabled) VALUES(:s, :l, :lbl, :u, :p, 1)')
                            ->execute(['s' => $siteId, 'l' => $locale, 'lbl' => $menuItem['label'], 'u' => $menuItem['url'], 'p' => $menuItem['position']]);
                    }
                } else {
                    $exists = $pdo->prepare('SELECT 1 FROM cms_menu_items WHERE locale=:l AND url=:u LIMIT 1');
                    $exists->execute(['l' => $locale, 'u' => $menuItem['url']]);
                    if (!$exists->fetchColumn()) {
                        $pdo->prepare('INSERT INTO cms_menu_items(locale, label, url, position, is_enabled) VALUES(:l, :lbl, :u, :p, 1)')
                            ->execute(['l' => $locale, 'lbl' => $menuItem['label'], 'u' => $menuItem['url'], 'p' => $menuItem['position']]);
                    }
                }
            } catch (\Throwable) {
            }
        }

        self::saveProfileSetting($pdo, $scoped, $siteId, 'site_profile', $blueprintKey);
        ExtensionRuntime::reset();
        PageCacheService::flushAll();

        return [
            'blueprint' => $blueprintKey,
            'activated_modules' => $activated,
            'created_types' => $createdTypes,
            'seeded_pages' => $seededPages,
        ];
    }

    private static function saveProfileSetting(PDO $pdo, bool $scoped, int $siteId, string $key, string $value): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($scoped) {
                $sql = $driver === 'sqlite'
                    ? 'INSERT INTO cms_settings(site_id, setting_key, setting_value) VALUES(:s, :k, :v) ON CONFLICT(site_id, setting_key) DO UPDATE SET setting_value=:v2, updated_at=CURRENT_TIMESTAMP'
                    : 'INSERT INTO cms_settings(site_id, setting_key, setting_value) VALUES(:s, :k, :v) ON CONFLICT(site_id, setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value, updated_at=CURRENT_TIMESTAMP';
                $args = ['s' => $siteId, 'k' => $key, 'v' => $value];
                if ($driver === 'sqlite') $args['v2'] = $value;
                $pdo->prepare($sql)->execute($args);
                return;
            }
            $sql = $driver === 'sqlite'
                ? 'INSERT INTO cms_settings(setting_key, setting_value) VALUES(:k, :v) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:v2, updated_at=CURRENT_TIMESTAMP'
                : 'INSERT INTO cms_settings(setting_key, setting_value) VALUES(:k, :v) ON CONFLICT(setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value, updated_at=CURRENT_TIMESTAMP';
            $args = ['k' => $key, 'v' => $value];
            if ($driver === 'sqlite') $args['v2'] = $value;
            $pdo->prepare($sql)->execute($args);
        } catch (\Throwable) {
        }
    }
}
