# قرارداد قالب و ماژول VazinCMS

هر بسته یک پوشه یا ZIP با دقیقاً یک فایل `vazin-extension.json` در ریشه است. شناسهٔ فنی بسته سراسری و شامل حروف کوچک انگلیسی، عدد و خط تیره است.

## نمونهٔ ماژول

ساختار:

```text
my-module/
  vazin-extension.json
  bootstrap.php
```

manifest:

```json
{
  "schema": 1,
  "key": "my-module",
  "type": "module",
  "name": "ماژول من",
  "version": "1.0.0",
  "description": "یک قابلیت مستقل",
  "author": "Customer",
  "requires": {
    "php": ">=8.2.0",
    "vazincms": ">=10.8.0",
    "extensions": {}
  },
  "admin_navigation": [
    {"label": "ماژول من", "path": "/admin/my-module", "order": 100}
  ],
  "bootstrap": "bootstrap.php",
  "default_status": "inactive",
  "protected": false
}
```

فایل bootstrap باید بدون تولید خروجی یک callable برگرداند. مسیرهای دقیق هسته قابل جایگزینی نیستند؛ Router ابتدا مسیرهای هسته و سپس مسیرهای ماژول را بررسی می‌کند.

```php
<?php
use VazinCMS\ModuleContext;

return static function (ModuleContext $module): void {
    $module->get('/hello', static function (array $matches): void {
        echo 'Hello';
    });
    $module->on('content.saved', static function (array $payload): void {
        $pageId = (int)($payload['page_id'] ?? 0);
        // پردازش غیرمسدودکنندهٔ ماژول روی محتوای ذخیره‌شده
    });
};
```

برای مسیر متغیر از `regex()` با الگوی کامل `#^/...$#` استفاده کنید. روش‌های مجاز `GET`، `HEAD`، `POST`، `PUT`، `PATCH` و `DELETE` هستند.
منوی مدیریت به‌صورت declarative از `admin_navigation` خوانده می‌شود و فقط وقتی ماژول فعال و سالم است نمایش داده می‌شود.
فایل‌های CSS، JavaScript، تصویر و فونت را در `assets/` بگذارید و در bootstrap با `$module->assetUrl('app.css')` نشانی امن آن‌ها را بسازید؛ فایل PHP از این مسیر سرو نمی‌شود.

از نسخهٔ 10.8 رویدادهای `content.saved`، `content.deleted`، `content.imported` و
`scheduler.tick` در اختیار ماژول‌ها هستند. خطای یک handler ثبت و از بقیهٔ
handlerها جدا می‌شود؛ ماژول نباید به موفقیت handler برای اعتبار تراکنش اصلی
تکیه کند. payload را دادهٔ خارجی در نظر بگیرید و شناسه‌ها را دوباره اعتبارسنجی کنید.

## نمونهٔ قالب

قالب می‌تواند هر view عمومی را جایگزین کند و بقیه از قالب پایهٔ هسته fallback می‌شوند.

```text
my-theme/
  vazin-extension.json
  views/
    head.php
    foot.php
    site-home.php
  assets/
    theme.css
```

```json
{
  "schema": 1,
  "key": "my-theme",
  "type": "theme",
  "name": "قالب من",
  "version": "1.0.0",
  "requires": {
    "php": ">=8.2.0",
    "vazincms": ">=10.8.0",
    "extensions": {}
  },
  "views": {
    "head": "views/head.php",
    "foot": "views/foot.php",
    "site-home": "views/site-home.php"
  },
  "default_status": "inactive"
}
```

نشانی asset قالب فعال را با `VazinCMS\ThemeManager::assetUrl('theme.css')` بسازید. PHP از پوشهٔ asset سرو نمی‌شود.

## چرخهٔ مدیریت

- نصب/ارتقا: اعتبارسنجی و استخراج به staging، سپس جابه‌جایی اتمیک به runtime؛ ارتقا فقط با SemVer بالاتر و در حالت غیرفعال انجام می‌شود و نسخهٔ قبلی برای rollback در `.trash` می‌ماند.
- فعال‌سازی: کنترل PHP، نسخهٔ CMS، وابستگی‌ها و قرارداد bootstrap؛ فقط یک قالب هم‌زمان فعال است.
- غیرفعال‌سازی: routeهای ماژول در همان درخواست بعدی حذف می‌شوند.
- بایگانی: بستهٔ غیرفعال به `.trash` منتقل می‌شود و فوراً پاک دائمی نمی‌شود.
- بسته‌های همراه هسته قابل بایگانی نیستند، ولی ماژول همراه را می‌توان غیرفعال کرد.

کد PHP افزونه با دسترسی فرایند VazinCMS اجرا می‌شود. فقط بستهٔ مورداعتماد را نصب کنید و token، `.env`، dump دیتابیس یا فایل اجرایی سیستم‌عامل را داخل بسته قرار ندهید.
