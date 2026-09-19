# VazinCMS 10.6.2 — پروفایل revision-aware

- وابستگی دقیق به Vazin ID 5.4.3 و مصرف claim عددی `profile_revision`.
- اعمال کامل نام و تنظیمات فقط برای revision بزرگ‌تر؛ پاسخ قدیمی‌تر هیچ projection را بازنویسی نمی‌کند.
- غلبهٔ `preferences_shared=false` در revision برابر و پاک‌سازی فقط فیلدهای با منبع `vazin_id`.
- حفظ tombstone و نام قبلی در false قدیمیِ بدون revision و حفظ کامل overrideهای محلی.
- true قدیمی بدون revision optional خراب را تفسیر نمی‌کند؛ URL تصویر و `updated_at` دقیقاً با خروجی واقعی Vazin ID 5.4.3 هم‌قرارداد است.
- قفل ردیف در PostgreSQL و تراکنش فوری SQLite برای جلوگیری از resurrect در callbackهای هم‌زمان.
- مهاجرت 10.6.2 و نصب/rollback دقیق 10.6.1↔10.6.2 برای چهار سایت allowlist‌شده.
