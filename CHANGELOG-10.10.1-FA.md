# VazinCMS 10.10.1

- مسیر ارتقای مستقیم و اتمی 10.6.1 به 10.10.1 برای tenantهای Travel و Visa
  اضافه شد؛ runtime، uploads و SQLite خارج از code root باقی می‌مانند.
- پیش از switch، archive کد، محیط، runtime و backup معتبر پایگاه‌داده ساخته و
  journal پایدار ثبت می‌شود؛ recovery خودکار فقط code را بازمی‌گرداند و runtime
  خارجی را دست‌نخورده نگه می‌دارد.
- worker سازگار باید قرارداد external-runtime-v1 را اجرا کند؛ این بسته
  bootstrap نیست و فقط برای دو سایت managed تعیین‌شده قابل استفاده است.
