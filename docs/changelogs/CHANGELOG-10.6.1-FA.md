# VazinCMS 10.6.1 — نسخه پایدار

- ارتقای دقیق `itgala.ru` از 10.3.0 و `pay`، `travel` و `visa.vazin.online` از 10.6.0؛ ریشهٔ پنجم یا نسخهٔ مبهم پذیرفته نمی‌شود.
- حذف ادغام خودکار حساب بر پایهٔ ایمیل و اعمال اتصال subject-only با ایمیل تأییدشده.
- مصرف allowlist پروفایل Vazin ID 5.4.2: نام استاندارد، نام کاربری، تصویر، زبان، قالب، زمان و provenance اشتراک‌گذاری.
- رابط چهارزبانهٔ fa/en/ru/ar با حفظ byte دقیق دادهٔ مشتری و URLها.
- ذخیره‌سازی و uploads بیرون از release، مهاجرت/rollback journaled، تعویض اتمیک Linux `renameat2(RENAME_EXCHANGE)` و توقف fail-closed روی حالت مبهم.
- نبود legacy `public/uploads` به‌صورت یک دایرکتوری خالی، service-owned و fsync‌شده provision می‌شود؛ symlink یا مسیر نامعتبر همچنان fail-closed است.
- health سه سایت عمومی از HTTPS loopback با hostname/certificate و پاسخ دقیق `VazinCMS` استفاده می‌کند تا redirect اجباری HTTP با health اشتباه نشود. ریشهٔ فنی IT Gala که عمداً در Nginx عمومی route نشده، با PHP/DB/version محلی و همان کاربر سرویس بررسی می‌شود.
- استخراج‌های محدود ۰۷۰۰/۰۶۰۰ پیش از اجرای تست با کاربر سرویس، داخل candidate به ۰۷۵۵/۰۶۴۴ root-owned نرمال می‌شوند؛ بسته برای نصب به chmod دستی بیرونی وابسته نیست.
- این بسته پس از عبور از بازبینی مستقل پایدار شده است؛ نصب زنده فقط با SHA-256 دقیق، پشتیبان معتبر و مسیر rollback ثبت‌شده انجام می‌شود.
