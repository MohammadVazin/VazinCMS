<?php
use VazinCMS\Security;
$connections=is_array($connections??null)?$connections:[];
?>
<section class="panel">
  <h1>دستیار Telegram و رزرو</h1>
  <p>پروفایل دستیار را روی یکی از اتصال‌های موجود بسازید. ساخت پروفایل هیچ شبکه، Webhook یا پاسخ خودکاری را فعال نمی‌کند.</p>
  <?php if(!empty($error)):?><p class="alert error"><?=Security::e((string)$error)?></p><?php endif;?>
  <?php if($connections===[]):?>
    <p class="alert warning">ابتدا در بخش Telegram یک اتصال غیرفعال بسازید.</p>
    <p><a class="button" href="/admin/telegram">رفتن به تنظیمات Telegram</a></p>
  <?php else:?>
    <form method="post" action="/admin/telegram/assistant" class="stack-form">
      <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
      <label>اتصال Telegram<select name="connection_id" required><?php foreach($connections as$connection):?><option value="<?=(int)$connection['id']?>"><?=Security::e((string)$connection['name'])?> · <?=Security::e((string)$connection['locale'])?></option><?php endforeach;?></select></label>
      <label>نام دستیار<input name="assistant_name" maxlength="120" required value="دستیار سایت"></label>
      <label>زبان<select name="locale"><option value="ru">Русский</option><option value="en">English</option><option value="fa">فارسی</option></select></label>
      <label>منطقهٔ زمانی<input name="timezone" maxlength="80" required value="Asia/Novosibirsk" dir="ltr"></label>
      <label>پیام خوش‌آمد<textarea name="welcome" maxlength="1000" rows="4">سلام، من دستیار این مجموعه هستم. برای مشاهده خدمات، رزرو یا ارتباط با مدیر همراه شما هستم.</textarea></label>
      <button class="button primary" type="submit">ساخت پروفایل خاموش</button>
    </form>
  <?php endif;?>
</section>
