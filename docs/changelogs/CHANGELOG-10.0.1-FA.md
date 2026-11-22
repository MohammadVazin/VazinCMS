# VazinCMS 10.0.1

- جداسازی `VAZIN_ID_URL`، `VAZINPAY_API_URL` و `VAZIN_ONLINE_URL`
- انتقال خودکار مقدار پرداخت نسخه 10.0.0 به متغیر صحیح هنگام ارتقا
- حفظ مسیر سازگاری برای نصب‌هایی که بدون نصب‌کننده ارتقا داده می‌شوند
- جلوگیری از ارسال درخواست Payment Intent به سرویس واقعی Vazin ID
- تغییر namespace داخلی از `VazinOnline` به `VazinCMS`
- تغییر نام Session به `vazin_cms_session`
- اصلاح عنوان README و مستندات Connector v2
- تعیین Vazin Online 9.1.2 به‌عنوان حداقل نسخه سازگار
- بدون تغییر دیتابیس و بدون حذف محتوا، کاربران، سفارش‌ها یا تنظیمات
