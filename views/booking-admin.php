<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">ماژول رسمی Schema v2 · نوبت‌دهی و خدمات</span>
    <h1>مدیریت نوبت‌دهی و رزروها (Vazin Booking)</h1>
    <p>تعریف خدمات قابل رزرو، ظرفیت هر بازه زمانی و بررسی نوبت‌های ثبت‌شده.</p>
  </div>
  <div class="title-actions">
    <span class="metric"><b><?= count($services) ?></b> خدمت فعال</span>
    <span class="metric success"><b><?= count($reservations) ?></b> نوبت رزروشده</span>
    <a class="button secondary" href="/booking" target="_blank" rel="noopener">صفحه رزرو عمومی ↗</a>
  </div>
</section>

<?php if ($message !== ''): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="grid two">
  <article class="card">
    <h2>تعریف خدمت قابل رزرو</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
      <label>عنوان خدمت<input name="title" required placeholder="مثلاً: جلسه مشاوره تخصصی"></label>
      <label>مدت هر نوبت (دقیقه)<input type="number" min="5" max="480" name="duration_minutes" value="30"></label>
      <label>ظرفیت هم‌زمان هر اسلات<input type="number" min="1" max="100" name="capacity_per_slot" value="1"></label>
      <label>هزینه خدمت<input type="number" step="0.01" min="0" name="price" value="0"></label>
      <label>توضیحات<textarea name="description" rows="3"></textarea></label>
      <button type="submit">ذخیره خدمت</button>
    </form>
  </article>

  <article class="card">
    <h2>نوبت‌های ثبت‌شده اخیر</h2>
    <?php if ($reservations): ?>
      <div class="cms-content-items">
        <?php foreach ($reservations as $r): ?>
          <div class="cms-content-item">
            <span class="cms-content-text">
              <strong><code dir="ltr"><?= Security::e((string) $r['booking_code']) ?></code> — <?= Security::e((string) $r['customer_name']) ?></strong>
              <small>تاریخ: <code dir="ltr"><?= Security::e((string) $r['slot_date']) ?> <?= Security::e((string) $r['slot_time']) ?></code> · <?= Security::e((string) $r['customer_email']) ?></small>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p>هنوز نوبتی رزرو نشده است.</p>
    <?php endif; ?>
  </article>
</section>
