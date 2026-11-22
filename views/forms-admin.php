<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">ماژول رسمی Schema v2 · ضداسپم Honeypot</span>
    <h1>فرم‌ساز پویا و صندوق پیام‌ها (Vazin Forms)</h1>
    <p>ساخت فرم‌های تعاملی قابل استفاده در صفحه‌ساز بصری و مشاهده پیام‌های دریافتی کاربران.</p>
  </div>
  <div class="title-actions">
    <span class="metric"><b><?= count($submissions) ?></b> پیام دریافتی</span>
  </div>
</section>

<?php if ($message !== ''): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="grid two">
  <article class="card">
    <h2>ایجاد یا ویرایش فرم</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
      <label>شناسه فنی فرم (مثلاً <code>contact-us</code>)<input name="form_key" dir="ltr" required value="contact-us"></label>
      <label>عنوان نمایشی فرم<input name="title" required value="فرم تماس با ما"></label>
      <label>پیام موفقیت پس از ارسال<input name="success_message" value="پیام شما با موفقیت ثبت شد."></label>
      <label>ساختار فیلدها (JSON)
        <textarea name="fields_json" dir="ltr" rows="5">[{"key":"full_name","label":"نام و نام خانوادگی","type":"text","required":true},{"key":"email","label":"ایمیل","type":"email","required":true},{"key":"message","label":"متن پیام","type":"textarea","required":true}]</textarea>
      </label>
      <button type="submit">ذخیره فرم</button>
    </form>
  </article>

  <article class="card">
    <h2>صندوق ورودی پیام‌ها</h2>
    <?php if ($submissions): ?>
      <div class="cms-content-items">
        <?php foreach ($submissions as $sub): ?>
          <div class="cms-content-item">
            <span class="cms-content-text">
              <strong>فرم: <code dir="ltr"><?= Security::e((string) $sub['form_key']) ?></code></strong>
              <small dir="ltr"><?= Security::e((string) $sub['payload_json']) ?></small>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p>هنوز پیامی از طریق فرم‌ها ارسال نشده است.</p>
    <?php endif; ?>
  </article>
</section>
