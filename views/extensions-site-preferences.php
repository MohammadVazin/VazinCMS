<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="panel">
  <h1>درخواست تنظیمات قالب‌ها و افزونه‌های این سایت</h1>
  <p>این صفحه فقط درخواست‌های آینده را در همین سایت ثبت می‌کند. <strong>هیچ افزونه یا قالبی در محیط اجرا فعال، غیرفعال، نصب یا حذف نمی‌شود.</strong> اجرای واقعی نیازمند تأیید امضا، جداسازی runtime و انتشار امن است.</p>
  <?php if(!empty($message)): ?><div class="alert success" role="status"><?= Security::e((string)$message) ?></div><?php endif; ?>
  <?php if(!empty($error)): ?><div class="alert error" role="alert"><?= Security::e((string)$error) ?></div><?php endif; ?>
</section>
<section class="grid two" aria-label="فهرست تنظیمات پیشنهادی سایت">
  <?php foreach($extensions as $extension): ?>
    <?php
      $key=(string)$extension['extension_key'];
      $type=(string)$extension['extension_type'];
      $eligible=!empty($extension['can_request']);
    ?>
    <article class="card">
      <span class="eyebrow"><?= $type==='theme'?'قالب':'ماژول' ?></span>
      <h2><?=Security::e((string)$extension['name'])?></h2>
      <p><?=Security::e((string)($extension['description']??''))?></p>
      <p><code><?=Security::e($key)?></code> · <?=Security::e((string)$extension['version'])?></p>
      <?php if($type==='theme'): ?>
        <p><?=!empty($extension['requested_theme'])?'قالب پیشنهادی برای آینده':'هنوز برای این سایت انتخاب نشده است'?></p>
      <?php else: ?>
        <p><?= $extension['requested_enabled']===null
              ? 'درخواست ثبت نشده'
              : ($extension['requested_enabled']?'درخواست فعال‌سازی ثبت شده':'درخواست غیرفعال‌سازی ثبت شده') ?></p>
      <?php endif; ?>
      <?php if($eligible): ?>
        <form method="post" class="stack">
          <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
          <input type="hidden" name="extension_key" value="<?=Security::e($key)?>">
          <?php if($type==='theme'): ?>
            <input type="hidden" name="action" value="request_theme">
            <button type="submit">ثبت انتخاب قالب برای این سایت</button>
          <?php else: ?>
            <input type="hidden" name="action" value="request_module">
            <button name="desired" value="enabled" type="submit">درخواست فعال‌سازی</button>
            <button name="desired" value="disabled" type="submit" class="button secondary">درخواست غیرفعال‌سازی</button>
          <?php endif; ?>
        </form>
      <?php else: ?>
        <p class="muted">این بسته برای انتخاب در این مرحله مجاز نیست؛ فقط بسته‌های همراه هسته قابل درخواست‌اند.</p>
      <?php endif; ?>
      <p class="muted">وضعیت اجرایی: بدون تغییر</p>
    </article>
  <?php endforeach; ?>
</section>
