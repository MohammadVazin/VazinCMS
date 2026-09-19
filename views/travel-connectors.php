<?php
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">Vazin Travel · White-label</span>
    <h1>کانکتورهای قراردادی سفر</h1>
    <p>این صفحه فقط مدل عملیاتی و متادیتای اتصال را نگه می‌دارد؛ ساختن این رکورد هیچ جست‌وجو، رزرو یا فراخوانی شبکه‌ای انجام نمی‌دهد.</p>
  </div>
  <a class="button secondary" href="/admin/travel">مدیریت محتوا و سفر</a>
</section>

<?php if ($error): ?><div class="alert error" role="alert"><?=Security::e($error)?></div><?php endif; ?>
<?php if ($message): ?><div class="alert success"><?=Security::e($message)?></div><?php endif; ?>
<div class="alert">
  برای هر تأمین‌کننده ابتدا قرارداد، فروشندهٔ حقوقی و مسئولیت صدور را مشخص کنید. تا زمانی که توسعهٔ adapter و آزمون جداگانه انجام نشده، هیچ وضعیت پنل به معنی اتصال واقعی نیست.
</div>

<section class="content-layout">
  <form class="editor-card" method="post">
    <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
    <input type="hidden" name="action" value="create">
    <div class="card-head"><div><h2>کانکتور جدید</h2><p>کلید یا credential اختیاری است و پس از ذخیره فقط به‌شکل رمزنگاری‌شده نگه‌داری می‌شود؛ هرگز در پنل نمایش داده نمی‌شود.</p></div></div>
    <div class="form-grid">
      <label>نام نمایشی<input name="label" maxlength="120" required placeholder="مثلاً تأمین‌کنندهٔ اقامت A"></label>
      <label>شناسهٔ فنی<input class="ltr-value" dir="ltr" name="provider_key" maxlength="64" required pattern="[a-z0-9][a-z0-9-]{1,63}" placeholder="stays-provider-a"></label>
      <label>حالت فروش<select name="mode"><?php foreach ($modes as $value => $label): ?><option value="<?=Security::e($value)?>"><?=Security::e($label)?></option><?php endforeach; ?></select></label>
      <label>وضعیت قراردادی<select name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?=Security::e($value)?>" <?=$value === 'draft' ? 'selected' : ''?>><?=Security::e($label)?></option><?php endforeach; ?></select></label>
      <label>نشانی قراردادی API یا مرجع (اختیاری)<input class="ltr-value" dir="ltr" type="url" name="endpoint" maxlength="2048" placeholder="https://partner.example/api"></label>
      <label>مقدار محرمانهٔ جدید (اختیاری)<input class="ltr-value" dir="ltr" type="password" name="credentials" maxlength="4000" autocomplete="new-password" placeholder="پس از ذخیره نمایش داده نمی‌شود"></label>
    </div>
    <fieldset>
      <legend>قابلیت‌هایی که قرارداد پوشش می‌دهد</legend>
      <div class="form-grid">
        <?php foreach ($capabilities as $value => $label): ?><label><input type="checkbox" name="capabilities[]" value="<?=Security::e($value)?>"> <?=Security::e($label)?></label><?php endforeach; ?>
      </div>
    </fieldset>
    <label class="switch-row"><input type="checkbox" name="contract_confirmed" value="1"><span class="switch"></span><span><b>قرارداد و مسئولیت فروش برای حالت active بررسی شده است.</b></span></label>
    <button>ذخیره متادیتای کانکتور</button>
  </form>

  <aside class="list-card">
    <div class="card-head"><div><h2>اتصال‌های ثبت‌شده</h2><p>هیچ مقدار محرمانه، URL امضاشده یا پاسخ تأمین‌کننده در این فهرست نشان داده نمی‌شود.</p></div></div>
    <?php foreach ($connectors as $connector): ?>
      <article class="page-item">
        <div class="page-main">
          <strong><?=Security::e($connector['label'])?></strong>
          <code dir="ltr"><?=Security::e($connector['provider_key'])?></code>
          <span class="status"><?=Security::e($statuses[$connector['status']] ?? $connector['status'])?></span>
          <small><?=Security::e($modes[$connector['mode']] ?? $connector['mode'])?> · <?=Security::e($connector['updated_at'])?></small>
          <small>قابلیت قرارداد: <?=Security::e(implode('، ', array_map(static fn(string $value): string => $capabilities[$value] ?? $value, $connector['capabilities'])) ?: '—')?></small>
          <small><?=((int) $connector['credentials_configured'] === 1) ? 'مقدار محرمانه ذخیره شده است و نمایش داده نمی‌شود.' : 'مقدار محرمانه‌ای ذخیره نشده است.'?></small>
        </div>
        <details>
          <summary>ویرایش متادیتا</summary>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="connector_id" value="<?=(int) $connector['id']?>">
            <div class="form-grid">
              <label>نام نمایشی<input name="label" maxlength="120" required value="<?=Security::e($connector['label'])?>"></label>
              <label>شناسهٔ فنی<input class="ltr-value" dir="ltr" name="provider_key" maxlength="64" required pattern="[a-z0-9][a-z0-9-]{1,63}" value="<?=Security::e($connector['provider_key'])?>"></label>
              <label>حالت فروش<select name="mode"><?php foreach ($modes as $value => $label): ?><option value="<?=Security::e($value)?>" <?=$connector['mode'] === $value ? 'selected' : ''?>><?=Security::e($label)?></option><?php endforeach; ?></select></label>
              <label>وضعیت قراردادی<select name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?=Security::e($value)?>" <?=$connector['status'] === $value ? 'selected' : ''?>><?=Security::e($label)?></option><?php endforeach; ?></select></label>
              <label>نشانی قراردادی API یا مرجع<input class="ltr-value" dir="ltr" type="url" name="endpoint" maxlength="2048" value="<?=Security::e((string) $connector['endpoint'])?>"></label>
              <label>جایگزینی مقدار محرمانه<input class="ltr-value" dir="ltr" type="password" name="credentials" maxlength="4000" autocomplete="new-password" placeholder="برای حفظ مقدار قبلی خالی بگذارید"></label>
            </div>
            <fieldset>
              <legend>قابلیت‌های قرارداد</legend>
              <div class="form-grid">
                <?php foreach ($capabilities as $value => $label): ?><label><input type="checkbox" name="capabilities[]" value="<?=Security::e($value)?>" <?=in_array($value, $connector['capabilities'], true) ? 'checked' : ''?>> <?=Security::e($label)?></label><?php endforeach; ?>
              </div>
            </fieldset>
            <label><input type="checkbox" name="clear_credentials" value="1"> حذف مقدار محرمانهٔ فعلی</label>
            <label class="switch-row"><input type="checkbox" name="contract_confirmed" value="1"><span class="switch"></span><span><b>قرارداد و مسئولیت فروش برای حالت active دوباره بررسی شده است.</b></span></label>
            <button>ذخیره تغییرات</button>
          </form>
        </details>
      </article>
    <?php endforeach; ?>
    <?php if ($connectors === []): ?><div class="empty-state">هنوز هیچ کانکتوری ثبت نشده است.</div><?php endif; ?>
  </aside>
</section>
