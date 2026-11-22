<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<main class="container" style="max-width:960px;margin:0 auto;padding:32px 16px;">
  <h1>رزرو آنلاین نوبت · <?= Security::e((string) ($siteName ?? '')) ?></h1>
  <p>خدمت و زمان موردنظر خود را انتخاب کنید تا کد پیگیری رزرو برایتان صادر شود.</p>

  <?php if (!empty($bookingReceipt)): ?>
    <section class="card" style="padding:20px;margin-bottom:24px;border:2px solid #1f8f5f;border-radius:16px;">
      <h2>نوبت شما قطعی شد</h2>
      <p>کد پیگیری رزرو: <strong dir="ltr"><?= Security::e((string) $bookingReceipt['booking_code']) ?></strong></p>
      <p>زمان: <code dir="ltr"><?= Security::e((string) $bookingReceipt['slot_date']) ?> <?= Security::e((string) $bookingReceipt['slot_time']) ?></code></p>
    </section>
  <?php endif; ?>
  <?php if (!empty($bookingError)): ?>
    <div class="alert error" style="margin-bottom:20px;"><?= Security::e((string) $bookingError) ?></div>
  <?php endif; ?>

  <?php if ($services === []): ?>
    <p>در حال حاضر خدمتی برای رزرو آنلاین تعریف نشده است.</p>
  <?php else: ?>
    <article class="card" style="padding:24px;border-radius:16px;border:1px solid #dce3ee;">
      <form method="post" action="/booking">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
        <label>انتخاب خدمت
          <select name="service_id" required>
            <?php foreach ($services as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= Security::e((string) $s['title']) ?> (<?= (int) $s['duration_minutes'] ?> دقیقه)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>تاریخ مراجعه<input type="date" name="slot_date" required value="<?= gmdate('Y-m-d', strtotime('+1 day')) ?>"></label>
        <label>ساعت<input type="time" name="slot_time" required value="10:00"></label>
        <label>نام و نام خانوادگی<input name="customer_name" required></label>
        <label>ایمیل<input type="email" name="customer_email" required></label>
        <label>تلفن تماس<input name="customer_phone" dir="ltr"></label>
        <label>توضیحات تکمیلی<textarea name="notes" rows="2"></textarea></label>
        <button type="submit">تأیید و دریافت کد پیگیری</button>
      </form>
    </article>
  <?php endif; ?>
</main>
