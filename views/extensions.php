<?php
declare(strict_types=1);
use VazinCMS\{CapabilityGuard, Security};
?>
<section class="page-head">
  <div>
    <span class="eyebrow">معماری میکروکرنل · Schema v2</span>
    <h1>قالب‌ها و ماژول‌ها</h1>
    <p>هسته فقط امکانات پایه را نگه می‌دارد؛ قابلیت‌های تخصصی را با مجوزهای ایزوله و شفاف مدیریت کنید.</p>
  </div>
  <div class="title-actions">
    <a class="button secondary" href="/admin/blueprints">کیت‌های آماده سایت</a>
  </div>
</section>

<?php if ($message): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="card" aria-labelledby="core-update-title">
  <div class="page-head">
    <div>
      <h2 id="core-update-title">به‌روزرسانی هسته</h2>
      <p>فقط فید رسمی و امضاشده بررسی می‌شود؛ دریافت و اجرای خودکار کد از راه دور عمداً غیرفعال است.</p>
    </div>
    <span class="badge <?= ($updateStatus['status'] ?? '') === 'update_available' ? '' : 'muted' ?>">
      <?= Security::e(match ($updateStatus['status'] ?? 'unchecked') {
          'update_available' => 'نسخهٔ جدید موجود است',
          'current' => 'به‌روز',
          'unavailable' => 'فید در دسترس نیست',
          default => 'هنوز بررسی نشده',
      }) ?>
    </span>
  </div>
  <p>
    نسخهٔ فعلی: <code dir="ltr"><?= Security::e((string) ($updateStatus['current_version'] ?? '')) ?></code>
    <?php if (!empty($updateStatus['available_version'])): ?>
      · نسخهٔ فید: <code dir="ltr"><?= Security::e((string) $updateStatus['available_version']) ?></code>
    <?php endif; ?>
  </p>
  <?php if (($updateStatus['status'] ?? '') === 'update_available' && !empty($updateStatus['release_url'])): ?>
    <p>
      <a class="button" href="<?= Security::e((string) $updateStatus['release_url']) ?>" rel="noopener noreferrer">مشاهدهٔ بستهٔ رسمی</a>
      <?php if (!empty($updateStatus['release_notes_url'])): ?>
        <a class="link" href="<?= Security::e((string) $updateStatus['release_notes_url']) ?>" rel="noopener noreferrer">یادداشت انتشار</a>
      <?php endif; ?>
    </p>
  <?php elseif (($updateStatus['status'] ?? '') === 'unavailable' && !empty($updateStatus['error_message'])): ?>
    <div class="alert error">فید رسمی قابل تأیید نیست؛ هیچ ارتقایی انجام نشده است.</div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
    <input type="hidden" name="action" value="refresh_update_feed">
    <button type="submit">بررسی نسخهٔ رسمی</button>
  </form>
</section>

<section class="card">
  <h2>افزودن بستهٔ جدید (ZIP)</h2>
  <p>بسته باید دارای <code>vazin-extension.json</code> معتبر (نسخه Schema 1 یا Schema 2) باشد. بسته‌های بیرونی تا زمان تأیید در قرنطینه غیرفعال می‌مانند.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
    <input type="hidden" name="action" value="install">
    <label>فایل بسته (.zip)<input type="file" name="package" accept=".zip,application/zip" required></label>
    <button type="submit">بررسی و ثبت بسته</button>
  </form>
</section>

<section class="grid two">
<?php foreach ($extensions as $extension):
    $manifest = $extension['manifest'] ?? [];
    $status = (string) $extension['status'];
    $schemaVer = (int) ($manifest['schema'] ?? 1);
    $perms = is_array($manifest['permissions'] ?? null) ? $manifest['permissions'] : [];
    $siteScoped = !empty($manifest['site_scoped']);
    $category = (string) ($manifest['category'] ?? ($extension['extension_type'] === 'theme' ? 'theme' : 'general'));
?>
  <article class="card">
    <div class="eyebrow">
      <?= ($extension['extension_type'] === 'theme' ? 'قالب' : 'ماژول') ?>
      · <?= ($extension['source'] === 'bundled' ? 'رسمی همراه هسته' : ($extension['source'] === 'runtime' ? 'نصب‌شده' : 'قدیمی')) ?>
      · Schema v<?= $schemaVer ?>
      <?php if ($siteScoped): ?> · ایزوله چندسایته<?php endif; ?>
      · دسته: <?= Security::e($category) ?>
    </div>
    <h2><?= Security::e((string) $extension['name']) ?></h2>
    <p><?= Security::e((string) ($extension['description'] ?? '')) ?></p>
    <p>
      <code dir="ltr"><?= Security::e((string) $extension['extension_key']) ?></code>
      · نسخه <code dir="ltr"><?= Security::e((string) $extension['version']) ?></code>
      · وضعیت: <strong><?= Security::e($status) ?></strong>
    </p>

    <?php if ($perms !== []): ?>
      <div class="cms-perm-list" style="display:flex;flex-wrap:wrap;gap:6px;margin:10px 0;">
        <?php foreach ($perms as $perm): ?>
          <span class="badge muted" title="<?= Security::e((string) (CapabilityGuard::ALL_CAPABILITIES[$perm] ?? $perm)) ?>">
            <code dir="ltr"><?= Security::e((string) $perm) ?></code>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($extension['compatibility_errors'])): ?>
      <div class="alert error">
        <?php foreach ($extension['compatibility_errors'] as $problem): ?>
          <div><?= Security::e((string) $problem) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="actions">
      <?php if ($status !== 'active' && $status !== 'removed' && empty($extension['compatibility_errors'])): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
          <input type="hidden" name="action" value="activate">
          <input type="hidden" name="extension_key" value="<?= Security::e((string) $extension['extension_key']) ?>">
          <button type="submit">فعال‌سازی</button>
        </form>
      <?php endif; ?>
      <?php if ($status === 'active' && $extension['extension_type'] === 'module'): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
          <input type="hidden" name="action" value="deactivate">
          <input type="hidden" name="extension_key" value="<?= Security::e((string) $extension['extension_key']) ?>">
          <button type="submit" class="secondary">غیرفعال‌سازی</button>
        </form>
      <?php endif; ?>
      <?php if ($extension['source'] === 'runtime' && $status !== 'active' && $status !== 'removed'): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
          <input type="hidden" name="action" value="archive">
          <input type="hidden" name="extension_key" value="<?= Security::e((string) $extension['extension_key']) ?>">
          <button type="submit" class="danger">انتقال به بایگانی</button>
        </form>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</section>
