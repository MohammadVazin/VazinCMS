# OP-02.4 — شواهد خواندنی وضعیت PHP-FPM از سوکت اختصاصی

وضعیت: DRAFT / NO-GO — این سند و ابزار هیچ مجوزی برای انتشار یا تغییر Production نمی‌دهند.

## هدف و محدودیت

در OP-02.3 مشاهده شد که Travel و Visa همچنان از Pool عمومی PHP-FPM استفاده می‌کنند. تست منفی مستقل نیز نشان داد درخواست قدیمی می‌تواند پس از دریافت 503 برای درخواست‌های تازه همچنان در فایل بنویسد. از این‌رو، وضعیت ایستای Pool و Fence عمومی برای Drain کافی نیست.

ابزار scripts/fpm_status_evidence.py، تنها از یک Unix status socket مستقل و از قبل ایجادشده، وضعیت PHP-FPM را به‌صورت خواندنی نمونه‌برداری می‌کند. این ابزار هیچ سرویس یا تنظیماتی را تغییر نمی‌دهد و به HTTP عمومی، SQL یا Workerها دست نمی‌زند.

## طراحی آزمایشی Pool اختصاصی

نمونه تنظیمات صرفاً برای محیط ایزوله:

    pm.status_path = /__cms_fpm_status
    pm.status_listen = /run/php/vazincms-travel-status.sock

نام Pool نمونه vazincms-travel است. مسیر Status باید Unix socket خصوصی با مجوز محدود باشد، نه endpoint عمومی. مالکیت UID/GID، Session، OPcache، درخواست‌های قدیمی، Workerهای مستقل و دسترسی tenantها باید جداگانه ممیزی شوند. دو Pool با UID یکسان، به‌تنهایی ایزولاسیون امنیتی tenant محسوب نمی‌شوند.

دستور خواندنی نمونه پس از ایجاد و تأیید سوکت در Staging:

    python3 scripts/fpm_status_evidence.py \
      --socket /run/php/vazincms-travel-status.sock \
      --pool vazincms-travel --samples 5 --interval-ms 500

خروجی JSON شمار Workerهای فعال و آزاد، عمق صف و تغییر تعداد اتصال‌های پذیرفته‌شده را گزارش می‌کند. اطلاعات حساس محیطی به فرایند FastCGI منتقل نمی‌شود و محتوای خام Status یا آدرس‌های محرمانه در خروجی منتشر نمی‌شوند.

حتی با مشاهده چند نمونه آرام (active=0، listen_queue=0 و accepted_conn ثابت) فقط observed_quiet=true مجاز است. در تمام حالات drain_verified=false، background_jobs_verified=false، release_authorized=false، decision=NO_GO و کد خروج 70 است. سوکت نامعتبر، نام Pool اشتباه و پاسخ ناقص نیز Fail-Closed هستند.

## شواهد فنی ۱۰ اکتبر ۲۰۲۶

در آزمایش ایزوله PHP-FPM 8.5 و سوکت Status مستقل، قبل از درخواست: active=0، idle=1، queue=0. هنگام درخواست PHP طولانی: active=1، idle=0، queue=0. پس از پایان: active=0، idle=1. نمونه‌برداری با خود اسکریپت مخزن هم این وضعیت‌ها را تشخیص داد و در هر دو حالت NO_GO ماند.

نسخه بازتولیدکننده در tests/integration/fpm_status_evidence_rehearsal.py موجود است؛ فقط برای Staging ایزوله، بدون داده مشتری و نه برای Production.

## کنترل‌های باز

- Pool اختصاصی واقعی Travel/Visa هنوز ایجاد و تأیید نشده و وضعیت عمومی Pool نمی‌تواند به یک سایت نسبت داده شود.
- نمونه‌برداری از Worker، اتمیک با Swap نیست؛ ممکن است بین آخرین نمونه و تعویض کد، Worker جدید شروع شود.
- Jobهای Cron/Queue، Webhookها، Writerهای خارج از PHP-FPM، اتصال‌های دیتابیس و درخواست‌های پس‌زمینه مستقلاً باید کنترل شوند.
- Drain باید با سیاست مجاز، رسید معتبر per-site، بازه مشاهده کافی، Rollback و DR، امضای نسخه و تأیید مالک تکمیل شود.

گیت نهایی همچنان NO-GO است.
