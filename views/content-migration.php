<?php
use VazinCMS\Security;
$title = 'انتقال محتوا';
$items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
?>
<section class="panel">
  <h1>انتقال محتوا</h1>
  <p>فایل خروجی را بررسی کنید و سپس محتوا را به‌صورت پیش‌نویس وارد کنید. برای اتصال تصویر شاخص محلی، ابتدا آرشیو رسانه را وارد کنید.</p>
  <?php if ($message): ?><div class="alert success"><?=Security::e($message)?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?=Security::e($error)?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
    <label>فایل خروجی <input type="file" name="migration_file" accept=".xml,.json,application/xml,application/json,text/xml" required></label>
    <p class="muted">پشتیبانی‌شده: WordPress WXR، Joomla J2XML، Ghost JSON و خروجی JSON قابل‌حمل VazinCMS. حداکثر حجم: ۱۰ مگابایت.</p>
    <button type="submit">بررسی فایل</button>
  </form>
</section>
<section class="panel">
  <h2>انتقال رسانه از ZIP</h2>
  <p>آرشیو رسانه را جداگانه وارد کتابخانه کنید. فقط JPG، PNG، WebP، GIF و PDF معتبر پذیرفته می‌شوند؛ فایل‌های تکراری یا ناامن وارد نمی‌شوند.</p>
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
    <input type="hidden" name="action" value="media">
    <label>آرشیو رسانه <input type="file" name="media_archive" accept=".zip,application/zip" required></label>
    <p class="muted">سقف آرشیو ۵۰ مگابایت، هر فایل ۱۰ مگابایت و حداکثر ۵۰۰ ورودی است. نخستین تصویر هر نوشته، فقط در صورت تطبیق با یک فایل محلی، به تصویر شاخص تبدیل می‌شود.</p>
    <button type="submit">بررسی و ورود رسانه‌ها</button>
  </form>
</section>
<?php if ($items): ?>
<section class="panel">
  <h2>پیش‌نمایش امن (<?=count($items)?> مورد)</h2>
  <p>همهٔ موارد با وضعیت پیش‌نویس و بدون دریافت فایل‌های رسانه‌ای وارد می‌شوند. کد فعال و HTML ناامن به متن تبدیل شده است.</p>
  <div class="table-wrap" role="region" tabindex="0" aria-label="پیش‌نمایش انتقال محتوا"><table><thead><tr><th>عنوان</th><th>نوع</th><th>زبان</th><th>شناسهٔ مبدأ</th></tr></thead><tbody>
  <?php foreach (array_slice($items,0,20) as $item): ?><tr><td><?=Security::e($item['title'])?></td><td><?=Security::e($item['content_type'])?></td><td dir="ltr"><?=Security::e($item['locale'])?></td><td dir="ltr"><?=Security::e($item['source_ref'])?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if (count($items) > 20): ?><p class="muted">فقط ۲۰ مورد اول نمایش داده شده است.</p><?php endif; ?>
  <form method="post" class="stack"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="commit"><button type="submit">انتقال <?=count($items)?> مورد به پیش‌نویس‌ها</button></form>
</section>
<?php endif; ?>
