# VazinCMS v11.1.0

**Next-Generation Capability-Isolated, Module-Centric Content Management System**  
*Engineered to outperform WordPress in security, speed, multi-tenancy, and modularity — suitable for every website.*

[نسخهٔ فارسی در پایین صفحه](#-راهنمای-فارسی-vazincms-v11) · [Official Portal](https://cms.vazin.online) · [Module SDK v2](docs/MODULE-SDK-V2-FA.md) · [Security Policy](SECURITY.md)

---

## Why VazinCMS Beats WordPress

| Capability | Legacy WordPress | VazinCMS v11 (Micro-Kernel) |
| :--- | :--- | :--- |
| **Plugin Security** | Any plugin has full root access to DB, `.env`, and `users` | **Capability-Based Schema v2 (`permissions`)** + isolated `mod_<key>_*` tables + Ed25519 sandbox |
| **Crash Resilience** | One faulty plugin triggers a White Screen of Death | **Fault-Isolated Hook, Filter & Block Bus** — module exceptions never crash pages |
| **Data Model** | Slow EAV `wp_postmeta` joins degrade at scale | **Dynamic Content Engine** — structured single-table rows + typed schema validation |
| **Core Features** | Needs 20+ conflicting plugins (Elementor, Yoast, WPML, ACF, WP Rocket) | **Built-in** Visual Template Builder, Multilingual (RTL/LTR), SEO, Edge Page Cache & Headless API |
| **Site Readiness** | Manual plugin stitching per project | **7 One-Click Site Blueprints** (Corporate, E-Commerce, Magazine, Academy/LMS, Booking, Portfolio, Blog) |
| **Databases** | MySQL only | **SQLite** (zero-config instant start), **PostgreSQL**, and **MySQL/MariaDB** |
| **Multi-Tenancy** | Fragile table-prefix Multisite | **Native `site_id` Isolation (`SiteScoped*`)** across Core & Schema v2 modules |

---

## 30-Second Quick Start

### Option A: Zero-Config Local PHP (SQLite)
```bash
git clone https://github.com/MohammadVazin/VazinCMS.git
cd VazinCMS
cp .env.example .env

# Install & apply a site blueprint (corporate | ecommerce | magazine | academy | booking | portfolio | blog)
php bin/vazin install --name="Site Owner" --email="owner@example.com" --password="StrongPassword123!" --blueprint=ecommerce

# Start local server
php -S 127.0.0.1:8080 -t public
```

### Option B: Docker Compose
```bash
docker compose up -d --build
```

---

## Official Open-Core Modules (`extensions/modules/`)

- **`vazin-commerce`** — Products, SKUs, stock inventory, coupons, cart, orders, pluggable `PaymentGatewayInterface`, and `vazin-commerce/product-grid` visual block.
- **`vazin-lms`** — Courses, curriculum lessons, student enrollments, progress tracking, completion certificates, and `vazin-lms/course-grid` block.
- **`vazin-booking`** — Bookable services, time-slot capacity enforcement, reservation tracking codes, and `vazin-booking/service-scheduler` block.
- **`vazin-forms`** — Schema-driven interactive forms, honeypot anti-spam, submissions inbox, and `vazin-forms/form` block.
- **`vazin-comments`** — Comments, Q&A, 1–5 star product/course/post ratings, moderation queue, and `vazin-comments/reviews` block.
- **`vazin-analytics`** — Privacy-first cookieless analytics (pageviews, top pages, device stats) with zero external JS bloat.
- **`vazin-memberships`** — VIP subscription plans, time-bound member grants, and `vazin-memberships/paywall` gated content block.
- **`seo`** — Sitemaps, Robots, Canonical/OpenGraph metadata, and SEO advisories.
- **`publishing`** — Editorial workflows, scheduled publishing, and multi-channel syndication.
- **`content-migration`** — One-click **WordPress WXR XML Importer** (migrates posts, pages, authors, terms, and media).

### Official Bundled Themes (`extensions/themes/`)
- **`vazin-default`** — Clean, fast multilingual universal starter theme.
- **`vazin-editorial`** — Typography-first theme built for news portals, magazines, and blogs.
- **`vazin-storefront`** — Conversion-focused theme for e-commerce, academies, and SaaS sites.

Scaffold your own Schema v2 module in 1 second:
```bash
php bin/vazin module:make my-directory --name="Business Directory"
```

---

## راهنمای فارسی VazinCMS v11

**VazinCMS نسخه ۱۱** یک سیستم مدیریت محتوای نسل جدید، ۱۰۰٪ **ماژول‌محور**، فوق‌سریع و مبتنی بر معماری **میکروکرنل (Open Core)** است که برای غلبه بر ضعف‌های امنیتی و سرعتی وردپرس طراحی شده و برای **تمام انواع وب‌سایت‌ها** آماده است.

### ویژگی‌های کلیدی نسخه ۱۱
- **معماری ماژول‌محور مبتنی بر مجوز (Schema v2):** هر ماژول در `vazin-extension.json` دقیقاً مجوزهای موردنیاز خود (`db:isolated`, `blocks:register`, `routes:api`, `http:outbound` و ...) را اعلام می‌کند و تنها به جداول ایزوله خودش (`mod_<key>_*`) دسترسی دارد.
- **پایداری ضدکرش (Fault Isolation):** خطا در یک ماژول یا بلوک هرگز باعث سفید شدن یا از کار افتادن کل سایت نمی‌شود.
- **۷ کیت آماده راه‌اندازی (Site Blueprints):** راه‌اندازی یک‌کلیکی سایت‌های **شرکتی**، **فروشگاهی**، **خبری/مجله‌ای**، **آموزشگاه آنلاین (LMS)**، **نوبت‌دهی و رزرواسیون**، **نمونه‌کار/پورتفولیو** و **وبلاگ**.
- **امکانات بومی در هسته:** صفحه‌ساز و قالب‌ساز بصری، چندزبانگی کامل (فارسی، انگلیسی، عربی، روسی و ...)، موتور انواع محتوا و فیلدهای پویا (جایگزین بومی ACF/CPT)، کش فوق‌سریع صفحات، Headless REST API و ابزار مهاجرت مستقیم از وردپرس.

### ابزار خط فرمان توسعه‌دهندگان (`bin/vazin`)
```bash
php bin/vazin status
php bin/vazin blueprint:list
php bin/vazin blueprint:apply academy
php bin/vazin module:list
php bin/vazin module:make custom-crm --name="مدیریت مشتریان"
php bin/vazin security:audit
```

### مجوز و حقوق مالکیت
کپی‌رایت © ۲۰۲۶ **Vazin Online**. کد هسته و ماژول‌های عمومی تحت مجوز [AGPL-3.0-or-later](LICENSE) منتشر شده است. استفاده از نام و نشان تجاری تابع [TRADEMARKS.md](TRADEMARKS.md) است.
