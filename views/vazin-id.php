<?php use VazinCMS\Security; ?>
<section class="admin-title"><div><span class="eyebrow">هویت مشترک</span><h1>Vazin ID</h1><p>ورود مستقیم کاربران این سایت به سرویس مرکزی Vazin ID؛ این اتصال وایت‌لیبل نیست.</p></div></section>
<?php if($error):?><div class="alert error"><?=Security::e($error)?></div><?php endif;?>
<?php if($message):?><div class="alert success"><?=Security::e($message)?></div><?php endif;?>
<section class="editor-card">
 <form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
  <div class="form-grid">
   <label>نشانی Vazin ID<input dir="ltr" type="url" name="issuer_url" placeholder="https://id.vazin.online" value="<?=Security::e($settings['issuer_url']??'')?>"></label>
   <label>Client ID<input dir="ltr" name="client_id" value="<?=Security::e($settings['client_id']??'')?>"></label>
   <label>Scopeها<input dir="ltr" name="scopes" value="<?=Security::e($settings['scopes']??'openid profile email')?>"></label>
   <label>منبع تنظیم فعلی<input disabled value="<?=Security::e(($settings['source']??'database')==='environment'?'فایل محیطی':'پنل این سایت')?>"></label>
  </div>
  <label class="switch-row"><input type="checkbox" name="is_enabled" value="1" <?=!empty($settings['is_enabled'])?'checked':''?>><span class="switch"></span><span><b>ورود با Vazin ID فعال باشد</b><small>ورود اضطراری مالک همچنان در صفحه ورود باقی می‌ماند.</small></span></label>
  <label class="switch-row"><input type="checkbox" name="auto_provision" value="1" <?=!empty($settings['auto_provision'])?'checked':''?>><span class="switch"></span><span><b>ساخت خودکار حساب مشتری</b><small>برای کاربران تأییدشدهٔ جدید، حساب محلی با نقش مشتری ساخته شود.</small></span></label>
  <button>ذخیره اتصال</button>
 </form>
</section>
