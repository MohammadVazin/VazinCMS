# VazinCMS

VazinCMS is an open-source, modular content management system for multilingual, branded and managed sites.

نسخهٔ فارسی این راهنما در ادامه آمده است.

## Support

- Official CMS site and documentation: <https://cms.vazin.online>
- Security reports: see [SECURITY.md](SECURITY.md)
- Community support and issue tracking: GitHub Issues

## Quick start

Requirements: PHP 8.2+, a supported database, and a web server. See `deploy/install.sh` and `.env.example` before installation. Do not publish your `.env`, credentials, database dumps, signing keys, or uploaded user files.

```sh
cp .env.example .env
# set the required values in .env, then run the documented installer
./deploy/install.sh
```

Current release: **10.30.0**. The supported upgrade predecessor is 10.29.0 according to `vazin-package.json`. The administrator panel verifies the official update feed but never downloads or executes remote code; use the release package and rollback procedure documented in the official support portal.

## Extensibility

- Modules live in `extensions/modules`.
- Themes live in `extensions/themes`.
- Core extension contracts and examples are in `docs/EXTENSIONS-FA.md`.

## Copyright and trademark

Copyright (c) 2026 Vazin Online. VazinCMS is licensed under the GNU Affero General Public License v3.0 or later; see [LICENSE](LICENSE).

The names **Vazin**, **Vazin Online**, **VazinCMS**, the official logos and `cms.vazin.online` are not granted for use by the software license. See [TRADEMARKS.md](TRADEMARKS.md).

---

## راهنمای فارسی

VazinCMS یک سامانهٔ مدیریت محتوای ماژولار و متن‌باز برای سایت‌های چندزبانه، برندپذیر و مدیریت‌شده است.

### پشتیبانی رسمی

- سایت و مستندات رسمی: <https://cms.vazin.online>
- گزارش آسیب‌پذیری: [SECURITY.md](SECURITY.md)
- گزارش اشکال و گفت‌وگوی عمومی: GitHub Issues

برای نصب، ابتدا `.env.example` را به `.env` تبدیل کنید، مقادیر محیطی را تنظیم کنید و سپس `deploy/install.sh` را طبق مستندات اجرا کنید. فایل `.env`، کلیدها، خروجی دیتابیس و فایل‌های کاربران نباید در گیت یا محیط عمومی قرار بگیرند.

### حقوق مالکیت

کپی‌رایت VazinCMS متعلق به **Vazin Online، ۲۰۲۶** است. کد با مجوز AGPL-3.0-or-later منتشر می‌شود؛ استفاده از نام، لوگو و دامنهٔ رسمی وزین تابع [سیاست علائم تجاری](TRADEMARKS.md) است.
