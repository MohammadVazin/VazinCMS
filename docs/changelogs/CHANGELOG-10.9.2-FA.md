# VazinCMS 10.9.2

## Bootstrap سایت‌های managed

- این release فقط entrypoint `deploy/bootstrap-install.sh` را برای ایجاد نخستین
  سایت managed معرفی می‌کند.
- installer فقط mode `managed-site-bootstrap` و profile `blank` را می‌پذیرد،
  root مقصد تازه، environment SQLite و مسیرهای runtime را fail-closed بررسی
  می‌کند و با `tar` استاندارد code را کپی می‌کند.
- این مرحله فقط code، `.env`، SQLite، logs و uploads را با مالکیت محدود provision
  می‌کند و هیچ credential مالک، migration یا بررسی Connector را اجرا نمی‌کند.
- worker سازگار VazinOnline 16.8.24 مالک اولیه، migration و پاسخ امضاشدهٔ
  `/connector/v2/status` را بعد از provision کنترل می‌کند.

`deploy/install.sh` و `deploy/rollback.sh` برای استفادهٔ legacy جدا هستند و
entrypoint این release نیستند.
