# نقشه راه VazinCMS 10.31.0

این سند نقطه شروع نسخه 10.31.0 است. نسخه 10.31.0 تا زمانی که artifact رسمی 10.30.2 وارد repository نشده، قابل انتشار نیست.

## پیش‌نیاز انتشار

- همگام‌سازی artifact رسمی 10.30.2 با `main` شامل changelog، migration، installer، rollback و manifest
- تطبیق `VERSION`، `vazin-package.json`، release-check و Update Feed
- اجرای تست‌های SQLite و PostgreSQL روی همان artifact
- ساخت release package و checksum مستقل
- تأیید rollback و health پس از نصب در محیط staging

## محورهای نسخه 10.31.0

### 1. مدیریت چرخه انتشار

- قرارداد واحد برای version، update feed، manifest و rollback
- نمایش وضعیت دقیق به‌روزرسانی در پنل مدیریت
- جلوگیری از اعلام نسخه‌ای که artifact قابل‌دریافت آن وجود ندارد

### 2. افزونه و قالب

- قرارداد نسخه‌گذاری و compatibility برای module/theme
- فعال‌سازی، غیرفعال‌سازی و rollback افزونه به‌صورت اتمیک
- ثبت provenance و checksum برای بسته‌های رسمی

### 3. مهاجرت محتوا

- importer قابل‌استفاده برای WordPress، Joomla و خروجی‌های استاندارد
- dry-run، گزارش خطا و rollback برای مهاجرت
- نگهداری رسانه، نویسنده، taxonomy، permalink و metadata سئو

### 4. پنل مدیریت

- داشبورد عملیاتی برای سلامت، نسخه، افزونه‌ها و backup
- نقش‌ها و دسترسی‌های granular
- audit log قابل‌جست‌وجو بدون افشای secret یا داده حساس

### 5. کیفیت و امنیت

- تست contract برای API، extension، installer و upgrade
- اسکن secret و dependency در CI
- تست browser برای مسیرهای اصلی پنل و سایت عمومی
- مستندات فارسی و انگلیسی برای نصب، ارتقا، توسعه افزونه و گزارش امنیتی

## معیار خروج نسخه

نسخه فقط وقتی release می‌شود که baseline repository، artifact منتشرشده، Update Feed، manifest، تست‌ها، health و rollback همگی نسخه یکسان را گزارش کنند.
