<?php
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">Vazin Travel · White-label</span>
    <h1>صف درخواست‌های آژانس</h1>
    <p>مسیر دستیِ دریافت، بررسی و صلاحیت‌سنجی درخواست‌های همکاری؛ این صفحه هیچ tenant، فروش، رزرو یا اتصال بیرونی ایجاد نمی‌کند.</p>
  </div>
  <div class="title-actions">
    <a class="button secondary" href="/admin/travel-connectors">کانکتورهای قراردادی</a>
    <a class="button secondary" href="/admin/travel">محتوا و سفر</a>
  </div>
</section>

<?php if ($error): ?><div class="alert error" role="alert"><?=Security::e($error)?></div><?php endif; ?>

<section class="grid stats" aria-label="وضعیت صف درخواست‌ها">
  <?php foreach ($statuses as $value => $label): ?>
    <a class="panel" href="/admin/agency-inquiries?status=<?=rawurlencode($value)?>">
      <b><?=(int) ($counts[$value] ?? 0)?></b>
      <span><?=Security::e($label)?></span>
    </a>
  <?php endforeach; ?>
</section>

<section class="panel">
  <form class="filters compact" method="get">
    <label>وضعیت
      <select name="status">
        <option value="">همهٔ درخواست‌ها</option>
        <?php foreach ($statuses as $value => $label): ?><option value="<?=Security::e($value)?>" <?=$status === $value ? 'selected' : ''?>><?=Security::e($label)?></option><?php endforeach; ?>
      </select>
    </label>
    <button>فیلتر</button>
    <?php if ($status !== ''): ?><a class="button ghost" href="/admin/agency-inquiries">پاک‌کردن فیلتر</a><?php endif; ?>
  </form>
</section>

<section class="panel">
  <div class="card-head"><div><h2>درخواست‌ها</h2><p>برای خواندن اطلاعات تماس و تعیین مسئول، هر ردیف را باز کنید.</p></div></div>
  <div class="table">
    <table>
      <thead><tr><th>آژانس</th><th>حوزه‌ها</th><th>وضعیت</th><th>مسئول</th><th>به‌روزرسانی</th></tr></thead>
      <tbody>
        <?php foreach ($inquiries as $inquiry): ?>
          <tr>
            <td><a href="/admin/agency-inquiries/<?=(int) $inquiry['id']?>"><strong><?=Security::e($inquiry['organization'])?></strong></a><br><small><?=Security::e($inquiry['country'])?> · <?=Security::e($inquiry['contact_name'])?></small></td>
            <td><?=Security::e(implode('، ', $inquiry['service_labels']) ?: '—')?></td>
            <td><span class="status <?=Security::e($inquiry['status'])?>"><?=Security::e($statuses[$inquiry['status']] ?? $inquiry['status'])?></span></td>
            <td><?=Security::e((string) ($inquiry['assigned_name'] ?? '') ?: 'تخصیص‌نیافته')?></td>
            <td><?=Security::e((string) $inquiry['updated_at'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($inquiries === []): ?><div class="empty-state"><b>درخواستی برای نمایش نیست</b><p>فرم عمومی همکاری آژانس در نشانی <code dir="ltr">/fa/agency</code> درخواست‌های جدید را به این صف می‌آورد.</p></div><?php endif; ?>
</section>
