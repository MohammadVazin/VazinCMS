<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">مدل‌سازی بدون کدنویسی · جایگزین بومی CPT &amp; ACF</span>
    <h1>انواع محتوا و فیلدهای پویا</h1>
    <p>موجودیت‌های ساختاریافته (ملک، پروژه، خدمت، رویداد، آگهی و ...) را با فیلدهای تایپ‌شده و خروجی خودکار Headless REST API تعریف کنید.</p>
  </div>
  <div class="title-actions">
    <span class="metric"><b><?= count($types) ?></b> نوع محتوای ثبت‌شده</span>
  </div>
</section>

<?php if ($message !== ''): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="grid two">
  <article class="card">
    <h2>تعریف نوع محتوای جدید</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
      <input type="hidden" name="action" value="define_type">
      <label>شناسه فنی (انگلیسی، مثلاً <code>property</code> یا <code>case-study</code>)
        <input name="type_key" dir="ltr" required pattern="[a-z][a-z0-9-]{1,60}" placeholder="portfolio-project">
      </label>
      <label>عنوان مفرد
        <input name="label" required placeholder="مثلاً: نمونه‌کار">
      </label>
      <label>عنوان جمع
        <input name="plural_label" placeholder="مثلاً: نمونه‌کارها">
      </label>
      <label>توضیحات
        <input name="description" placeholder="توضیح کوتاه کاربرد این نوع محتوا">
      </label>
      <label>طرح فیلدها (JSON آرایه‌ای از فیلدها)
        <textarea name="fields_json" dir="ltr" rows="5">[{"key":"client_name","label":"نام کارفرما","type":"text","required":true},{"key":"budget","label":"بودجه","type":"price","required":false}]</textarea>
      </label>
      <button type="submit">ذخیره ساختار نوع محتوا</button>
    </form>
  </article>

  <article class="card">
    <h2>انواع محتوای فعال در سایت</h2>
    <div class="cms-content-items">
      <?php foreach ($types as $t): ?>
        <div class="cms-content-item">
          <span class="cms-content-text">
            <strong><?= Security::e((string) $t['label']) ?> (<code dir="ltr"><?= Security::e((string) $t['type_key']) ?></code>)</strong>
            <small>
              منبع: <?= Security::e((string) ($t['owner_module'] ?? 'custom')) ?>
              · فیلدهای سفارشی: <?= count($t['fields'] ?? []) ?>
              · API: <code dir="ltr">/api/v1/entries/<?= Security::e((string) $t['type_key']) ?></code>
            </small>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </article>
</section>
