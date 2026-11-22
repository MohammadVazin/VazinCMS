<?php
declare(strict_types=1);
use VazinCMS\{BlueprintManager, Security};
$blueprints = BlueprintManager::catalog();
$selectedBp = (string) ($old['blueprint'] ?? 'corporate');
$selectedLocale = (string) ($old['default_locale'] ?? 'fa');
?>
<section class="auth">
  <div class="logo">◆</div>
  <h1>راه‌اندازی هوشمند VazinCMS</h1>
  <p>نوع سایت خود را انتخاب کنید تا ماژول‌ها، صفحات نمونه و ساختار محتوا در چند ثانیه آماده شوند.</p>
  <?php foreach ($errors as $e): ?>
    <div class="alert error"><?= Security::e($e) ?></div>
  <?php endforeach; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
    <label>عنوان وب‌سایت
      <input name="site_name" required placeholder="مثلاً: فروشگاه من / آکادمی آنلاین" value="<?= Security::e((string) ($old['site_name'] ?? 'وب‌سایت من')) ?>">
    </label>
    <label>نوع سایت (کیت آماده یک‌کلیکی)
      <select name="blueprint">
        <?php foreach ($blueprints as $bp): ?>
          <option value="<?= Security::e((string) $bp['key']) ?>" <?= $selectedBp === $bp['key'] ? 'selected' : '' ?>>
            <?= Security::e((string) $bp['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>زبان پیش‌فرض سایت
      <select name="default_locale">
        <option value="fa" <?= $selectedLocale === 'fa' ? 'selected' : '' ?>>فارسی (RTL)</option>
        <option value="en" <?= $selectedLocale === 'en' ? 'selected' : '' ?>>English (LTR)</option>
        <option value="ar" <?= $selectedLocale === 'ar' ? 'selected' : '' ?>>العربية (RTL)</option>
        <option value="ru" <?= $selectedLocale === 'ru' ? 'selected' : '' ?>>Русский (LTR)</option>
      </select>
    </label>
    <label>نام مدیر کل (Owner)
      <input name="name" required value="<?= Security::e((string) ($old['name'] ?? '')) ?>">
    </label>
    <label>ایمیل مدیریت
      <input type="email" name="email" required value="<?= Security::e((string) ($old['email'] ?? '')) ?>">
    </label>
    <label>رمز عبور قوی (حداقل ۱۲ کاراکتر)
      <input type="password" name="password" minlength="12" required>
    </label>
    <button type="submit">نصب و پیکربندی خودکار سایت</button>
  </form>
</section>
