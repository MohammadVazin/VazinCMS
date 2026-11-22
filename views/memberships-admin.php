<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">ماژول رسمی Schema v2 · محتوای اشتراکی و Paywall</span>
    <h1>اشتراک ویژه و اعضای VIP (Vazin Memberships)</h1>
    <p>ایجاد پلن‌های عضویت زمان‌دار و اعطای دسترسی به محتوای اختصاصی با بلوک <code>vazin-memberships/paywall</code>.</p>
  </div>
  <div class="title-actions">
    <span class="metric"><b><?= count($plans) ?></b> پلن اشتراک</span>
    <span class="metric success"><b><?= count($subscriptions) ?></b> اشتراک فعال</span>
  </div>
</section>

<?php if ($message !== ''): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="grid two">
  <article class="card">
    <h2>ایجاد پلن اشتراک جدید</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
      <input type="hidden" name="action" value="create_plan">
      <label>شناسه فنی پلن (مثلاً <code>gold-monthly</code>)<input name="plan_key" dir="ltr" required placeholder="vip-monthly"></label>
      <label>عنوان پلن<input name="title" required placeholder="اشتراک طلایی ماهانه"></label>
      <label>مدت اعتبار (روز)<input type="number" min="1" name="duration_days" value="30"></label>
      <label>مبلغ<input type="number" step="0.01" min="0" name="price" value="0"></label>
      <button type="submit">ذخیره پلن</button>
    </form>
  </article>

  <article class="card">
    <h2>فعالسازی اشتراک برای کاربر</h2>
    <?php if ($plans): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
        <input type="hidden" name="action" value="grant">
        <label>انتخاب پلن
          <select name="plan_key">
            <?php foreach ($plans as $pl): ?>
              <option value="<?= Security::e((string) $pl['plan_key']) ?>"><?= Security::e((string) $pl['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>ایمیل کاربر<input type="email" name="member_email" required></label>
        <label>نام کاربر<input name="member_name" value="عضو ویژه"></label>
        <button type="submit">اعطای اشتراک</button>
      </form>
    <?php else: ?>
      <p>ابتدا یک پلن اشتراک بسازید.</p>
    <?php endif; ?>
  </article>
</section>
