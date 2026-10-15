# OP-02.12 — ممیزی امن Nginx -T و پیش‌شرط Drain سایت‌های مشترک

**تاریخ: ۱۵ اکتبر ۲۰۲۶ | وضعیت: DRAFT / Production: NO-GO**

## هدف

گزارش OP-02.11 مبتنی بر خواندن چند فایل vhost و include داخلی بود. برای تکمیل شواهد، OP-02.12 خروجی دستور فقط‌خواندنی `nginx -T` را **فقط در حافظه** تحلیل می‌کند. اسکریپت `scripts/readonly_nginx_effective_dump_audit.py` هیچ خط خام تنظیمات، نام فایل‌های واقعی، مقدار IP مورداعتماد، توکن، URL محرمانه، مقدار هدر یا متن خطای Nginx را در خروجی/مخزن ذخیره یا چاپ نمی‌کند. خروجی تنها شامل برچسب‌های ثابت پنج سایت، تعداد بخش‌ها، تعداد بلاک‌ها، کلاس‌های محدود Backend و نشانه‌های «هنوز تأییدنشده» است.

**تمایز مهم:** `nginx -T` پیکربندی **فعلی روی دیسک** را بازآزمایی و چاپ می‌کند؛ تأیید نمی‌کند Workerهای موجود دقیقاً همین محتوا را پیش‌تر load کرده‌اند. همچنین ترتیب فایل‌ها در dump الزاماً متن include را داخل محل واقعی استفاده در هر server block جایگذاری نمی‌کند. بنابراین شمارش locationها و ربط‌دادن snippetهای جداگانه به یک vhost، کامل یا قطعی نیست. حتی نتیجهٔ exit 0 هرگز GO نیست.

## روش و ایمنی

- فراخوانی ثابت `/usr/sbin/nginx -T` از Python، با timeout محدود، capture فقط در حافظه و سقف پردازش خروجی ۸ MiB.
- فقط خروجی JSON خلاصه‌شده بیرون می‌رود؛ خروجی خام stdout/stderr حتی هنگام شکست منتشر نمی‌شود. آرگومان اضافی مجاز نیست؛ خطا `INCOMPLETE` و کد 70 می‌دهد.
- parser مبتنی بر اسکن بلوک‌ها و نشانه‌های syntactic است، **نه parser رسمی کامل Nginx**؛ عبارت‌های داخل string/comment را تا حد امکان از directive واقعی تفکیک می‌کند.
- هیچ درخواست HTTP به سایت‌های زنده، تزریق XFF، تغییر vhost، `reload/restart`، دسترسی به توکن‌ها، دستکاری FPM یا دیتابیس انجام نمی‌دهد.
- آزمون‌های `tests/test_readonly_nginx_effective_dump_audit.py` فقط روی پیکربندی مصنوعی و mock خروجی subprocess هستند. CI نباید ممیزی واقعی سایت‌های Production را اجرا کند.
- اجرای دستی روی میزبان مجاز: `python3 scripts/readonly_nginx_effective_dump_audit.py`. نباید `nginx -T` خام را در چت، CI log یا مخزن منتشر کرد.

## شواهد واقعی S17 (۱۵ اکتبر ۲۰۲۶)

| کنترل | مشاهده |
|---|---|
| Nginx | نسخهٔ 1.28.3 |
| اجرای `nginx -T` | Exit 0 |
| بخش‌های پیکربندی در dump | ۱۵۴ |
| تعداد تقریبی server block در خروجی | ۲۱۲ |
| دستورهای صریح `set_real_ip_from`، `real_ip_header` و `real_ip_recursive` در dump | هر سه: ۰ |
| بلاک‌های مطابق با نام سرور CMS، Visa، Studio، Travel، RussiaFa | برای هر سایت: ۲ |
| نشانهٔ literal مسیر QA و هدر Fence در بدنهٔ بلاک‌های شناخته‌شده | برای هر پنج سایت: مشاهده نشد |
| Backend در بلاک‌های شناخته‌شده | Visa و Travel هر دو `shared_php85`؛ Studio و RussiaFa کلاس اختصاصی شناخته‌شده؛ CMS بدون تطبیق قطعی |
| نتیجهٔ ابزار | `production=NO_GO` و `loaded_worker_config_proven_equal_to_dump=false` |

وجود دو بلاک برای یک نام سرور ممکن است به HTTP/HTTPS، redirectها یا سایر قراردادهای Nginx مربوط باشد؛ این ابزار علت را بررسی نکرده است. گزارش نکردن QA/Fence در بدنهٔ این بلاک‌ها **ادعای نبودنشان در includeهای جداگانه نیست**.

## PHP-FPM / Drain

در S17، هر دو دامنهٔ Travel و Visa از Backend عمومی مشترک `php8.5-fpm.sock` استفاده می‌کنند. در یک snapshot فقط‌خواندنی، شمار فرایندهای Worker با نام pool `www` برابر ۸ بود. **این ۸ به‌معنی ۸ درخواست فعال نیست** و مشاهدهٔ صفر Worker هم به‌تنهایی «Drain» یا بلوکه‌بودن ورود درخواست تازه را ثابت نمی‌کند. ابزار جدید عمداً برچسب `per_site_drain_precondition=BLOCKED_SHARED_POOL`، `inflight_fpm_request_state_known=false`، `background_writers_quiesced=false` و `php_fpm_drain_verified=false` می‌دهد.

این مرحله هیچ تلاش ساختگی برای گرفتن رسید Worker Drain انجام نمی‌دهد. پیش از هر تعویض runtime باید: (۱) pool جداگانه با UID/مجوز tenant معتبر یا مکانیسم معتبر per-site برای انتساب درخواست فراهم شود، (۲) ورود درخواست جدید و webhook/cron/job writerها با سیاست fail-closed بسته شوند، (۳) درخواست‌های قدیمی واقعاً و اتمیک با نقطهٔ Swap شناسایی و drain شوند، (۴) اثر عملیات روی سایر سایت‌های Nginx/FPM مستقل آزموده شود. حتی همهٔ این‌ها بدون بازیابی بکاپ، RPO/RTO، OPcache، OAuth/پرداخت، امضای بسته و تأیید مالک کافی نیستند.

## نتیجه

این ممیزی ثابت می‌کند snapshot پیکربندی دیسک از نظر syntax پذیرفته شده و نشانه‌های ساختاری بالا در خروجی `nginx -T` مشاهده شده‌اند؛ **هیچ گیت Production را باز نمی‌کند**. مستندات و آزمون‌های جدید بخشی از نامزد PR وابسته به OP-02.11 هستند، نه تغییر Production.

**Candidate source QA: قابل آزمون؛ Production: NO-GO.**
