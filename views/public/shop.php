<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<main class="container" style="max-width:1120px;margin:0 auto;padding:32px 16px;">
  <header style="margin-bottom:28px;">
    <h1>فروشگاه آنلاین <?= Security::e((string) ($siteName ?? '')) ?></h1>
    <p>انتخاب محصول و ثبت سفارش آنی با تسویه‌حساب یکپارچه.</p>
  </header>

  <?php if (!empty($orderReceipt)): ?>
    <section class="card" style="padding:24px;margin-bottom:28px;border:2px solid #1f8f5f;border-radius:16px;">
      <h2>سفارش شما با موفقیت ثبت شد</h2>
      <p>شماره پیگیری سفارش: <strong dir="ltr"><?= Security::e((string) $orderReceipt['order_number']) ?></strong></p>
      <p>مبلغ نهایی: <strong><?= number_format((float) $orderReceipt['total']) ?> <?= Security::e((string) $orderReceipt['currency']) ?></strong></p>
      <?php if (!empty($orderReceipt['payment']['checkout_url'])): ?>
        <p><a class="button" href="<?= Security::e((string) $orderReceipt['payment']['checkout_url']) ?>">انتقال به درگاه پرداخت</a></p>
      <?php elseif (!empty($orderReceipt['payment']['instructions'])): ?>
        <p><?= Security::e((string) $orderReceipt['payment']['instructions']) ?></p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!empty($checkoutError)): ?>
    <div class="alert error" style="margin-bottom:20px;"><?= Security::e((string) $checkoutError) ?></div>
  <?php endif; ?>

  <?php if ($products === []): ?>
    <p>در حال حاضر محصول فعالی برای نمایش وجود ندارد.</p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;">
      <?php foreach ($products as $p):
        $available = !empty($p['is_digital']) || (int) $p['stock'] > 0;
      ?>
        <article class="card" style="padding:20px;border-radius:16px;border:1px solid #dce3ee;">
          <h2 style="margin-top:0;"><?= Security::e((string) $p['title']) ?></h2>
          <p><?= Security::e((string) $p['summary']) ?></p>
          <p><strong><?= number_format((float) $p['price']) ?> <?= Security::e((string) $p['currency']) ?></strong></p>
          <?php if ($available): ?>
            <form method="post" action="/shop">
              <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
              <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
              <label>تعداد<input type="number" name="qty" value="1" min="1" max="20" required></label>
              <label>نام و نام خانوادگی<input name="name" required></label>
              <label>ایمیل<input type="email" name="email" required></label>
              <label>کد تخفیف (اختیاری)<input name="coupon_code" dir="ltr"></label>
              <button type="submit">ثبت سفارش</button>
            </form>
          <?php else: ?>
            <span class="badge muted">ناموجود</span>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
