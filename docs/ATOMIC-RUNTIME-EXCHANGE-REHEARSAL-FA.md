# OP-02.7 — تمرین تعویض اتمیک نسل Runtime و بازیابی پس از SIGKILL

**وضعیت: فقط محیط آزمایشی / نامزد کد / NO-GO. ابزار مهاجرت Production نیست.**

## هدف

در OP-02.6 یک کپی از دادهٔ ساختگی با UID/GID جدید در کنار نسل قدیمی ساخته شد. این مرحله آزمایش مکانیزم جابه‌جایی دو اسلات پوشه را اضافه می‌کند: مسیر source ابتدا نسل قدیمی است و candidate نسل جدید با مالک مستقل. تنها روی دادهٔ ساختگی و در tmpfs با تابع Linux renameat2(RENAME_EXCHANGE)، دو پوشه در یک عملیات اتمیک جای خود را عوض می‌کنند.

**تغییر نام اتمیک، به معنای قطع اتمیک اتصال دیتابیس، Session، Workerهای PHP یا Jobهای پس‌زمینه نیست.** این موارد روی Production تغییر یا تأیید نشده‌اند.

## محدودهٔ سخت

ابزار فقط با نشانگرهای ساختگی معتبر در مسیر مستقیم /dev/shm/vazincms-identity-rehearsal-* قابل اجراست. root marker با مجوز 0600، نشانگرهای صریح در .env و SQLite، مالکیت و مجوزهای دقیق، هویت‌های Unix متمایز و عدم وجود Symlink/Hardlink الزامی هستند.

ابزار هیچ دستور Nginx/PHP-FPM، SQL تولید، DNS، chown دادهٔ فعال، useradd، توقف سرویس یا Installer اجرا نمی‌کند. یک lock انحصاری با مجوز root-only، Journal با مجوز 0600 و Inode/Device برای تشخیص جابه‌جایی اسلات‌ها وجود دارد.

نمونهٔ کلی در یک ریشهٔ مصنوعی از پیش ساخته‌شده:

    python3 scripts/isolated_runtime_exchange_rehearsal.py prepare --root /dev/shm/vazincms-identity-rehearsal-TEST --from-user www-data --to-user nobody
    python3 scripts/isolated_runtime_exchange_rehearsal.py switch --root /dev/shm/vazincms-identity-rehearsal-TEST
    python3 scripts/isolated_runtime_exchange_rehearsal.py status --root /dev/shm/vazincms-identity-rehearsal-TEST
    python3 scripts/isolated_runtime_exchange_rehearsal.py recover --root /dev/shm/vazincms-identity-rehearsal-TEST

**تمام دستورها حتی در موفقیت تصمیم NO_GO و کد خروج ۷۰ دارند.**

## پروتکل

1. prepare: هویت root، marker، مالکیت/محتوا/SQLite دو نسل و تطابق SHA256 بررسی می‌شود. Journal با Inode/Device، هویت کاربری و digest دادهٔ قبلی در حالت prepared ثبت می‌شود.
2. switch: پس از بررسی مجدد دادهٔ قدیمی و جدید، renameat2(RENAME_EXCHANGE) اجرا می‌شود. نسل جدید در source و قبلی در candidate قرار می‌گیرد و Journal به swapped می‌رود.
3. status: اسلات واقعی از Inode/Device شناسایی می‌شود؛ اگر Journal هنوز prepared اما نسل جدید فعال باشد، این اختلاف صریحاً گزارش می‌شود.
4. recover: تعویض معکوس، نسل قدیمی را به source بازمی‌گرداند و نسل جدید را **همراه تمام نوشته‌های بعد از Swap** در candidate حفظ می‌کند. بازیابی تکراری idempotent است. هیچ حذف یا ادغام خودکار دادهٔ جدید انجام نمی‌شود.
5. اگر بعد از تعویض و پیش از آپدیت Journal فرایند SIGKILL شود، در صورت شناخته‌شدن Inodeها، Recover از روی وضعیت اسلات واقعی عمل می‌کند. هویت غیرمنتظره، تغییر دادهٔ نسل قدیمی یا Journal مخدوش منجر به Fail-Closed می‌شود.

**حفظ نوشته‌های جدید به معنای بازیابی آن‌ها در سرویس قدیمی نیست.** این داده‌ها در نسل کنار گذاشته‌شده باقی می‌مانند؛ ادغام ایمن آن‌ها نیاز به سیاست مستقل Conflict Resolution، Queue Replay، مهاجرت Schema و تصمیم مالک دارد. حذف خودکار نسل جدید ممنوع است.

## نتایج واقعی آزمون ۱۰ اکتبر ۲۰۲۶

- دو دایرکتوری با renameat2 روی tmpfs تبادل شدند؛ Inode فعال با هویت نسل جدید تطابق داشت.
- پس از Swap با UID واقعی مقصد، نوشته‌ای در SQLite ساختگی درج شد. Recover نسل قبلی را فعال کرد؛ دیتابیس قدیمی بدون تغییر ماند و نوشتهٔ جدید در نسل کنارگذاشته‌شده حفظ شد.
- SIGKILL بلافاصله پس از Swap و قبل از آپدیت Journal، وضعیت prepared را در Journal باقی گذاشت اما Inodeها new_active بودند؛ Recover نسل قدیم را بازگرداند و دادهٔ تازه را حفظ کرد.
- SIGKILL پس از Swap برگشتی و قبل از ثبت Journal، با اجرای مجدد Recover از روی Inode شناسایی شد و تعویض تکراری رخ نداد.
- تغییر SQLite قدیمی پس از Prepare و قبل از Swap عملیات را متوقف کرد.
- ۱۸ تست واحد از جمله دو تست روی مقادیر نامعتبر Journal موفق شدند. سناریوهای SIGKILL در tests/integration/isolated_runtime_exchange_rehearsal.py هستند و در CI پیش‌فرض اجرا نمی‌شوند.

## محدودیت فنی «پایداری»

این تمرین روی tmpfs است. fsync در این محیط **تضمین بازیابی پس از قطع برق یا Crash هسته/دیسک نیست**. آزمون واقعی دوام داده روی SSD/HDD، خرابی IO، Crash Kernel، RPO/RTO و بکاپ Offsite هنوز انجام نشده است.

## موانع انتشار

- Installer/rollback واقعی همچنان به www-data و Journal دیگر وابسته‌اند؛ این ابزار به آن‌ها متصل نشده است.
- جداسازی Pool و UID در Production، توقف درخواست‌ها/Jobها، قفل نویسنده، انتشار امضاشده و بکاپ پایدار هنوز مورد تأیید نیست.
- برای داده‌های تازهٔ باقی‌مانده در نسل کنارگذاشته‌شده، ادغام و کنترل سازگاری مستقل لازم است.
- سوییچ با Worker/Sessionهای فعال، پرداخت، OAuth/Webhook و نگه‌داری دیتابیس مشتری آزمایش نشده است.
- بسته رسمی 10.30.2 بدون تغییر باقی می‌ماند و نامزد 10.30.3 منتشر یا امضا نشده است.

**نتیجه: REHEARSAL PASS / PRODUCTION NO-GO.**
