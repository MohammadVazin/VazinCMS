# VazinCMS 10.10.0

## پلتفرم وایت‌لیبل سفر

- صفحهٔ Travel از فروشندهٔ مستقیم به معرفی پلتفرم قابل‌برندسازی برای
  آژانس‌های دارای مجوز تغییر کرد.
- فرم `/{locale}/agency` برای دریافت سرنخ همکاری، با CSRF، رضایت تماس و
  اعتبارسنجی ورودی افزوده شد.
- پنل `/admin/travel-connectors` فقط متادیتای اتصال قراردادی را نگه می‌دارد:
  `lead_only`، `affiliate_redirect`، `manual_fulfilment` و `live_booking`.
  credentialها رمزنگاری می‌شوند و پس از ذخیره نمایش داده نمی‌شوند.
- هیچ provider، نرخ، بلیت، هتل یا رزروی بدون قرارداد و adapter جداگانه فعال
  نمی‌شود.

## پرونده ویزا و eVisa

- migrationهای کامل برای کاتالوگ ویزا، ملیت، مقصد، rule و مقصدهای سفر اضافه شد.
- فرم امن `/{locale}/order/{id}/application` هویت، گذرنامه، اقامت، برنامهٔ
  سفر، شغل و رضایت را دریافت می‌کند.
- داده‌های حساس فرم فقط به‌صورت AES-GCM context-bound در
  `visa_application_intakes` نگه‌داری می‌شوند و بعد از ثبت در صفحهٔ مشتری
  بازنمایی نمی‌شوند.
- ثبت یا بازثبت فرم یک رویداد پروندهٔ بدون payload حساس تولید می‌کند تا
  اپراتور بتواند آن را پیگیری کند.

## هشدار Telegram

- subscription و outbox مستقل Travel/Visa افزوده شد؛ هر پیام پیش از صف با
  `SecretStore` رمزنگاری می‌شود.
- مدیر و مشتری فقط با deep-link و Start کردن ربات همان tenant opt-in می‌شوند؛
  `/stop` اشتراک را revoke و کارهای در انتظار را لغو می‌کند.
- متن اعلان شامل شمارهٔ گذرنامه، فایل، مشخصات مسافر، یادداشت داخلی یا متن
  پیام پرونده نیست؛ فقط مرجع پرونده و لینک امن نیازمند ورود دارد.
- ارسال به‌طور پیش‌فرض خاموش است و به هر سه feature gate زیر نیاز دارد:
  `EXTERNAL_DELIVERY_ENABLED`، `TELEGRAM_NETWORK_ENABLED` و
  `TELEGRAM_ALERT_DELIVERY_ENABLED`.

## QA و سازگاری

- قرارداد UI Vazin با فونت محلی Vazirmatn، OFL، preload، RTL، focus و
  viewportهای 390×844 و 1440×1000 اضافه و بررسی شد.
- migrationهای SQLite و PostgreSQL و contract test مربوط به Travel/Visa افزوده
  شدند.
