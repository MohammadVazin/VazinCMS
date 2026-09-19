<?php
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">درخواست آژانس <bdi dir="ltr">#<?=(int) $inquiry['id']?></bdi></span>
    <h1><?=Security::e($inquiry['organization'])?></h1>
    <p>این درخواست همکاری توسط <?=Security::e($inquiry['contact_name'])?> ثبت شده است. برای ادامه، یک مسئول و مرحلهٔ بررسی تعیین کنید.</p>
  </div>
  <a class="button secondary" href="/admin/agency-inquiries">بازگشت به صف</a>
</section>

<?php if ($saved): ?><div class="alert success">وضعیت و مسئول پیگیری ذخیره شد. هیچ اتصال بیرونی یا پیام Telegram ارسال نشد.</div><?php endif; ?>
<?php if ($error): ?><div class="alert error" role="alert"><?=Security::e($error)?></div><?php endif; ?>

<section class="content-layout">
  <article class="editor-card">
    <div class="card-head"><div><h2>مشخصات همکاری</h2><p>اطلاعات تماس فقط برای پیگیری همین درخواست نگه‌داری می‌شود.</p></div></div>
    <dl class="detail-list">
      <div><dt>شخص تماس</dt><dd><?=Security::e($inquiry['contact_name'])?></dd></div>
      <div><dt>ایمیل</dt><dd dir="ltr"><?=Security::e($inquiry['email'])?></dd></div>
      <div><dt>تلفن</dt><dd dir="ltr"><?=Security::e($inquiry['phone'])?></dd></div>
      <div><dt>کشور فعالیت</dt><dd><?=Security::e($inquiry['country'])?></dd></div>
      <div><dt>وب‌سایت</dt><dd><?php if ($inquiry['website_url'] !== ''): ?><a dir="ltr" href="<?=Security::e($inquiry['website_url'])?>" target="_blank" rel="noopener noreferrer"><?=Security::e($inquiry['website_url'])?></a><?php else: ?>—<?php endif; ?></dd></div>
      <div><dt>زبان فرم</dt><dd><code dir="ltr"><?=Security::e($inquiry['locale'])?></code></dd></div>
      <div><dt>حوزه‌های درخواستی</dt><dd><?=Security::e(implode('، ', $inquiry['service_labels']) ?: '—')?></dd></div>
      <div><dt>ثبت</dt><dd><?=Security::e((string) $inquiry['created_at'])?></dd></div>
    </dl>
    <section class="panel"><h2>یادداشت آژانس</h2><p><?=nl2br(Security::e((string) $inquiry['notes'])) ?: 'یادداشتی ثبت نشده است.'?></p></section>
  </article>

  <aside class="list-card">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
      <input type="hidden" name="inquiry_id" value="<?=(int) $inquiry['id']?>">
      <div class="card-head"><div><h2>پیگیری داخلی</h2><p>این تغییر فقط صف داخلی را به‌روزرسانی می‌کند و در گزارش رویداد ثبت می‌شود.</p></div></div>
      <label>مرحلهٔ بررسی<select name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?=Security::e($value)?>" <?=$inquiry['status'] === $value ? 'selected' : ''?>><?=Security::e($label)?></option><?php endforeach; ?></select></label>
      <label>مسئول پیگیری<select name="assigned_user_id"><option value="0">هنوز تخصیص نده</option><?php foreach ($staff as $member): ?><option value="<?=(int) $member['id']?>" <?=(int) $inquiry['assigned_user_id'] === (int) $member['id'] ? 'selected' : ''?>><?=Security::e($member['name'])?> · <?=Security::e($member['role'])?></option><?php endforeach; ?></select></label>
      <button>ذخیره پیگیری</button>
    </form>
    <section class="panel">
      <h2>گیت‌های آماده‌سازی وایت‌لیبل</h2>
      <ol>
        <li>مجوز، فروشندهٔ حقوقی و مدل فروش آژانس را بررسی کنید.</li>
        <li>دامنه، برند، زبان‌ها و محیط اختصاصی را پیش از تحویل مشخص کنید.</li>
        <li>فقط پس از قرارداد، متادیتای کانکتور را در پنل مربوط ثبت و adapter را جداگانه آزمون کنید.</li>
        <li>هشدار Telegram فقط با پیکربندی و رضایت صریح گیرنده فعال می‌شود.</li>
      </ol>
    </section>
  </aside>
</section>
