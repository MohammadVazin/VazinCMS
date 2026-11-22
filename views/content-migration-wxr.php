<?php
use VazinCMS\Security;
$title='ورود امن وردپرس';
?>
<section class="panel">
 <h1>واردکردن امن فایل WXR وردپرس</h1>
 <p>فایل خروجی وردپرس فقط در سایت جاری به <strong>پیش‌نویس</strong> تبدیل می‌شود. برای حفظ امنیت، پیوست‌ها از اینترنت دانلود نمی‌شوند، تغییر مسیر خودکار ساخته نمی‌شود و کدهای HTML و افزونه‌ها اجرا نمی‌شوند.</p>
 <?php if($error): ?><p class="alert error" role="alert"><?= Security::e((string)$error) ?></p><?php endif; ?>
 <?php if($message): ?><p class="alert success" role="status"><?= Security::e((string)$message) ?></p><?php endif; ?>
 <form method="post" enctype="multipart/form-data" class="stack">
  <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
  <input type="hidden" name="action" value="wordpress_wxr">
  <label for="wordpress-wxr-file">فایل خروجی WordPress XML (WXR)</label>
  <input id="wordpress-wxr-file" type="file" name="wordpress_wxr" accept=".xml,.wxr,application/xml,text/xml" required>
  <label for="wordpress-wxr-language">زبان محتوای واردشده</label>
  <select id="wordpress-wxr-language" name="locale">
   <?php foreach(['fa'=>'فارسی','en'=>'English','ru'=>'Русский','ar'=>'العربية','tr'=>'Türkçe','hy'=>'Հայերեն','kk'=>'Қазақша','tg'=>'Тоҷикӣ','zh'=>'中文'] as $key=>$name): ?>
    <option value="<?=Security::e($key)?>"<?=$key==='fa'?' selected':''?>><?=Security::e($name)?></option>
   <?php endforeach; ?>
  </select>
  <p class="muted">حداکثر ۵ مگابایت و ۵۰۰ آیتم. در صورت وجود همان فایل در همان سایت، عملیات تکراری به‌جای ساخت صفحه جدید از نتیجه قبلی استفاده می‌کند. این قابلیت در کاندید آزمایشی است.</p>
  <button type="submit">ورود ایمن به پیش‌نویس‌های این سایت</button>
 </form>
</section>
<?php if(is_array($stats)): ?>
<section class="panel">
 <h2>گزارش ورود</h2>
 <dl>
  <dt>شماره عملیات</dt><dd><?= (int)$stats['job_id'] ?></dd>
  <dt>بررسی‌شده</dt><dd><?= (int)$stats['scanned'] ?></dd>
  <dt>پیش‌نویس ایجادشده</dt><dd><?= (int)$stats['created'] ?></dd>
  <dt>پیوست دریافت‌نشده</dt><dd><?= (int)$stats['attachments_skipped'] ?></dd>
 </dl>
 <p>برای بررسی و انتشار، به <a href="/admin/pages?type=all">صفحه‌ها و نوشته‌ها</a> بروید.</p>
</section>
<?php endif; ?>
