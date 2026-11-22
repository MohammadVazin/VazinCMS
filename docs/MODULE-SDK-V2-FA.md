# راهنمای توسعه ماژول نسل ۲ (Module SDK Schema v2)

در **VazinCMS v11**، سیستم افزونه‌ها بر پایه معماری **Zero-Trust & Capability-Based** طراحی شده است تا برخلاف وردپرس، هیچ ماژولی نتواند بدون مجوز به جداول حساس هسته دسترسی پیدا کند یا با خطای زمان اجرا کل سایت را متوقف کند.

## ۱. ساخت سریع ماژول با CLI

```bash
php bin/vazin module:make my-catalog --name="کاتالوگ پیشرفته"
```

## ۲. ساختار مانیفست (`vazin-extension.json` — Schema v2)

```json
{
  "schema": 2,
  "key": "my-catalog",
  "type": "module",
  "name": "کاتالوگ پیشرفته",
  "version": "1.0.0",
  "description": "نمایش و مدیریت آیتم‌های اختصاصی",
  "author": "Developer",
  "category": "general",
  "requires": {
    "php": ">=8.2.0",
    "vazincms": ">=11.0.0",
    "extensions": {}
  },
  "permissions": [
    "routes:public",
    "routes:admin",
    "routes:api",
    "blocks:register",
    "content:types",
    "db:isolated",
    "settings:manage",
    "hooks:listen",
    "hooks:filter"
  ],
  "site_scoped": true,
  "admin_navigation": [
    { "label": "کاتالوگ من", "path": "/admin/my-catalog", "order": 60 }
  ],
  "bootstrap": "bootstrap.php",
  "default_status": "inactive",
  "protected": false
}
```

## ۳. قابلیت‌های `ModuleContext`

- **دیتابیس ایزوله و چندسایته (`$module->db()`):**
  - ساخت جدول بدون درگیر شدن با تفاوت‌های SQLite / PostgreSQL / MySQL:
    ```php
    $db = $module->db();
    $db->ensureTable('items', [
        'slug'  => 'string:160',
        'title' => 'string:255',
        'price' => 'decimal',
    ], [['slug']]);
    $id = $db->insert('items', ['slug' => 'item-1', 'title' => 'نمونه', 'price' => 250000]);
    ```
  - تمام جداول به‌صورت خودکار با پیش‌وند `mod_<module_key>_` و ستون `site_id` ایزوله می‌شوند.
- **ثبت بلوک صفحه‌ساز بصری (`$module->registerBlock(...)`):** بلوک با نام `<module-key>/<block-name>` در `BlockEditor` ثبت و در محیط ایزوله در برابر خطا رندر می‌شود.
- **نقاط پایانی Headless REST API (`$module->api('GET', 'items', ...)`):** به‌صورت خودکار مسیر `/api/v1/modules/<module-key>/items` را با خروجی استاندارد JSON فعال می‌کند.
- **رویدادها و فیلترهای تایپ‌شده (`$module->on(...)` و `$module->filter(...)`):** با اولویت‌بندی (`priority`) و محافظت خودکار در برابر خطا و ناسازگاری نوع داده.
- **درگاه پرداخت پلاگین‌پذیر (`$module->registerPaymentGateway(...)`):** پیاده‌سازی رابط `VazinCMS\Contracts\PaymentGatewayInterface` برای اتصال یکپارچه به فروشگاه و رزرواسیون.
