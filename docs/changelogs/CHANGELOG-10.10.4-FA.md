# VazinCMS 10.10.4

- hotfix سازگاری قالب: دادهٔ متن اختصاصی فرم پروندهٔ دستی eVisa از متن عمومی
  پوسته جدا شد تا پوستهٔ فعال نتواند آن را overwrite کند.
- مسیر fallback عمومی همان CSS پوستهٔ فعال، preload فونت و labels B2B را
  می‌گیرد؛ fallback بدون runtime نیز برای قراردادهای تست امن باقی می‌ماند.
- فروش مستقیم Visa، VazinPay callback و delivery Telegram همچنان طبق gateهای
  fail-closed نسخهٔ 10.10.3 غیرفعال‌اند.
