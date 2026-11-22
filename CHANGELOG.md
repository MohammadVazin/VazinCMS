# Changelog

All notable changes to **VazinCMS** are documented in this file.

## [11.1.0] - 2026-11-22

### Added
- **Full Integrated Admin Workspace & Public Theme Views:** Upgraded `/install` with 1-click Site Blueprint & Default Locale wizard, added Schema v2 capability badges in `/admin/extensions`, and integrated full `View::render` / `View::renderPublic` UI across Commerce, LMS, Booking, Forms, Blueprints, and Dynamic Content Types.
- **3 New Official Schema v2 Modules:**
  - `vazin-comments`: Comments, Q&A, 1–5 star ratings, Honeypot anti-spam, moderation queue, and `vazin-comments/reviews` visual block.
  - `vazin-analytics`: Cookieless privacy-first analytics engine (`/admin/analytics`) with daily rotating visitor hashing and zero client-side bloat.
  - `vazin-memberships`: VIP subscription plans, member grants, and `vazin-memberships/paywall` gated content block.
- **Multi-Theme Ecosystem:** Added `vazin-editorial` (news/magazine) and `vazin-storefront` (commerce/SaaS) official bundled themes alongside cleaned Open-Core `vazin-default`.
- **Expanded OpenAPI 3.1 Specification:** Documented all `/api/v1/*` Headless & Schema v2 module endpoints.

## [11.0.0] - 2026-11-22

### Added
- **Pure Micro-Kernel Open Core Architecture:** Decoupled vertical/proprietary domain logic from `src/` so Core installs cleanly in 30 seconds on any server or local machine.
- **Module System v2 (`vazin-extension.json` Schema v2):**
  - Fine-grained capability enforcement via `CapabilityGuard` (`db:isolated`, `blocks:register`, `routes:api`, `content:types`, `payments:gateway`, `http:outbound` with SSRF protection, etc.).
  - `ModuleDatabase` portable, SQL-injection-proof, multi-tenant (`site_id`) isolated table manager across **SQLite, PostgreSQL, and MySQL/MariaDB**.
  - Fault-isolated priority `HookCollection` supporting both Actions (`emit`) and type-safe Filters (`applyFilters`).
  - `BlockRegistry` enabling modules to register custom visual builder blocks alongside new native Core blocks (`hero`, `features`, `pricing`, `faq`, `testimonial`, `cta`, `stats`, `callout`).
- **Dynamic Content Engine (`DynamicContentService`):** Custom Content Types and typed custom fields without `wp_postmeta` EAV join overhead.
- **Universal Site Blueprints (`BlueprintManager`):** 7 one-click starter kits (`corporate`, `ecommerce`, `magazine`, `academy`, `booking`, `portfolio`, `blog`).
- **4 New Official Schema v2 Open-Core Modules:** `vazin-commerce`, `vazin-lms`, `vazin-booking`, and `vazin-forms`.
- **Built-in Edge Page Cache (`PageCacheService`):** Sub-5ms anonymous GET/HEAD caching with ETag (`304 Not Modified`) and tag invalidation.
- **Headless REST API v1 (`/api/v1/*`)** & **Developer CLI (`bin/vazin`)** + `Dockerfile`, `docker-compose.yml`, and `composer.json`.

### Security
- Removed all internal operational host references and separated commercial vertical packages from the public repository.
- Enforced strict capability boundaries, SSRF private-IP rejection on module outbound calls, and inline script stripping on module-rendered visual blocks.

---

Historical release notes for versions `8.0.0` through `10.30.3` are archived under [`docs/changelogs/`](docs/changelogs/).
