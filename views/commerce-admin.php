<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">ماژول رسمی Schema v2 · دیتابیس ایزوله</span>
    <h1>فروشگاه‌ساز و سفارش‌ها (Vazin Commerce)</h1>
    <p>محصولات فیزیکی و دیجیتال، موجودی انبار، کدهای تخفیف و سفارش‌های مشتریان را مدیریت کنید.</p>
  </div>
  <div class="title-actions">
    <span class="metric"><b><?= count($products) ?></b> محصول</span>
    <span class="metric success"><b><?= count($orders) ?></b> سفارش</span>
    <a class="button secondary" href="/shop" target="_blank" rel="noopener">مشاهده ویترین فروشگاه ↗</a>
  </div>
</section>

<?php if ($message !== ''): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="grid two">
  <article class="card">
    <h2>افزودن محصول جدید</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
      <input type="hidden" name="action" value="create_product">
      <label>عنوان محصول<input name="title" required placeholder="مثلاً: اشتراک حرفه‌ای ابری"></label>
      <label>کد کالا (SKU)<input name="sku" dir="ltr" placeholder="PRO-001"></label>
      <label>قیمت<input type="number" step="0.01" min="0" name="price" required value="0"></label>
      <label>واحد پول<input name="currency" dir="ltr" value="IRR" maxlength="6"></label>
      <label>موجودی انبار<input type="number" min="0" name="stock" value="10"></label>
      <label><input type="checkbox" name="is_digital" value="1"> محصول دانلودی / دیجیتال (بدون محدودیت انبار فیزیکی)</label>
      <label>توضیحات کوتاه<textarea name="summary" rows="3"></textarea></label>
      <button type="submit">ثبت محصول در فروشگاه</button>
    </form>
  </article>

  <article class="card">
    <h2>ساخت کد تخفیف</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
      <input type="hidden" name="action" value="create_coupon">
      <label>کد تخفیف (انگلیسی)<input name="code" dir="ltr" required placeholder="WELCOME20"></label>
      <label>نوع تخفیف
        <select name="discount_type">
          <option value="percent">درصدی (%)</option>
          <option value="fixed">مبلغ ثابت</option>
        </select>
      </label>
      <label>مقدار تخفیف<input type="number" step="0.01" min="1" name="discount_value" required value="10"></label>
      <label>حداقل مبلغ سفارش<input type="number" step="0.01" min="0" name="min_order" value="0"></label>
      <button type="submit">ایجاد کد تخفیف</button>
    </form>

    <hr style="margin:20px 0;opacity:.2">
    <h3>آخرین سفارش‌ها</h3>
    <?php if ($orders): ?>
      <div class="cms-content-items">
        <?php foreach ($orders as $o): ?>
          <div class="cms-content-item">
            <span class="cms-content-text">
              <strong><code dir="ltr"><?= Security::e((string) $o['order_number']) ?></code> — <?= Security::e((string) $o['customer_name']) ?></strong>
              <small><?= number_format((float) $o['total']) ?> <?= Security::e((string) $o['currency']) ?> · وضعیت: <?= Security::e((string) $o['order_status']) ?></small>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p><small>هنوز سفارشی ثبت نشده است.</small></p>
    <?php endif; ?>
  </article>
</section>
