# VazinCMS 10.30.0

## فاز ۱۶ — اتصال Sandbox ماژول عمومی Travel/Visa

- اتصال Travel/Visa به Public Project رسمی VazinPay در `https://api.pay.vazin.online`.
- جداسازی آمادگی callback آزمایشی از فعال‌سازی فروش تجاری؛ `TRAVEL_VISA_COMMERCIAL_ENABLED=false` همچنان فروش واقعی را بسته نگه می‌دارد.
- همسان‌سازی امضای webhook با قرارداد رسمی VazinPay: کلید HMAC از SHA-256 secret یک‌بارمصرف مشتق می‌شود.
- حفظ tenant allow-list و fail-closed بودن callback.
- اضافه‌شدن contract testهای sandbox، readback، webhook و activation gate.
- هیچ فروش واقعی، دادهٔ شخصی production یا پردازش واقعی در این فاز فعال نمی‌شود.
