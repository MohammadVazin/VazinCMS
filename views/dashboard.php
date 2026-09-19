<?php use VazinCMS\{Security,SiteBrand}; ?>

<section class="hero">
  <div>
    <span class="eyebrow">پنل مدیریت <?=Security::e(SiteBrand::name())?></span>
    <h1>سلام، <?=Security::e($user['name'])?></h1>
    <p>مدیریت مشتریان، سفارش‌ها، سرویس‌ها و امور مالی کسب‌وکار</p>
  </div>

  <span class="badge">نسخه <?=Security::e($appVersion)?></span>
</section>

<?php if (is_array($releaseChannel)): ?>
<section class="panel" aria-live="polite">
  <?php if (!empty($releaseChannel['available'])): ?>

    <h2>نسخه جدید VazinCMS در دسترس است</h2>

    <p>
      نسخه
      <strong><?=Security::e((string)$releaseChannel['version'])?></strong>
      منتشر شده است.
    </p>

    <p>
      <?php if (!empty($releaseChannel['release_notes'])): ?>
        <a
          class="button"
          href="<?=Security::e((string)$releaseChannel['release_notes'])?>"
          target="_blank"
          rel="noopener noreferrer"
        >
          مشاهده تغییرات
        </a>
      <?php endif; ?>

      <a class="button" href="/admin">
        بررسی بروزرسانی
      </a>
    </p>

  <?php else: ?>

    <h2>بروزرسانی VazinCMS</h2>
    <p>نصب شما به‌روز است.</p>

  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (is_array($fleetStatus ?? null)): ?>
<section class="panel" aria-live="polite">
  <h2>وضعیت ناوگان VazinCMS</h2>
  <p>کنترل فقط‌خواندنی نسخه و سلامت نصب‌های رسمی وزین.</p>
  <div class="table"><table><thead><tr><th>سایت</th><th>نسخه</th><th>وضعیت</th></tr></thead><tbody>
    <?php foreach (($fleetStatus['sites'] ?? []) as $site): ?>
      <tr><td><span><?=Security::e((string)$site['label'])?></span><br><code dir="ltr"><?=Security::e((string)$site['host'])?></code></td><td><code dir="ltr"><?=Security::e((string)($site['version'] ?? '—'))?></code></td><td><span class="badge"><?=Security::e((string)$site['label_status'])?></span></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <p>نسخهٔ مرجع: <code dir="ltr"><?=Security::e((string)($fleetStatus['expected_version'] ?? '—'))?></code></p>
</section>
<?php endif; ?>

<section class="grid stats">
  <article>
    <b><?=$stats['users']?></b>
    <span>مشتری</span>
  </article>

  <article>
    <b><?=$stats['services']?></b>
    <span>سرویس فعال</span>
  </article>

  <article>
    <b><?=$stats['orders']?></b>
    <span>سفارش جاری</span>
  </article>

  <article>
    <b><?=$stats['tickets']?></b>
    <span>تیکت باز</span>
  </article>
</section>

<section class="panel">
  <h2>آخرین فعالیت‌ها</h2>

  <div class="table">
    <table>
      <thead>
        <tr>
          <th>رویداد</th>
          <th>شرح</th>
          <th>زمان</th>
        </tr>
      </thead>

      <tbody>
        <?php foreach($events as $e): ?>
          <tr>
            <td>
              <code><?=Security::e($e['action'])?></code>
            </td>
            <td><?=Security::e($e['description'])?></td>
            <td><?=Security::e((string)$e['created_at'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
