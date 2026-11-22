<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">پیکربندی هوشمند · مناسب تمام سایت‌ها</span>
    <h1>کیت‌های آماده راه‌اندازی سایت (Site Blueprints)</h1>
    <p>با یک کلیک، ترکیب ماژول‌های تخصصی، انواع محتوای سفارشی، منوها و صفحات آغازین متناسب با کسب‌وکار خود را فعال کنید.</p>
  </div>
</section>

<?php if ($message !== ''): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="grid two">
  <?php foreach ($catalog as $bp):
    $isCurrent = ($currentProfile ?? '') === $bp['key'];
  ?>
    <article class="card">
      <div class="eyebrow">
        <code dir="ltr"><?= Security::e((string) $bp['key']) ?></code>
        <?php if ($isCurrent): ?> · <strong>کیت فعال فعلی</strong><?php endif; ?>
      </div>
      <h2><?= Security::e((string) $bp['name']) ?></h2>
      <p><?= Security::e((string) $bp['description']) ?></p>
      <p><small>ماژول‌های همراه: <code dir="ltr"><?= Security::e(implode(', ', $bp['modules'])) ?></code></small></p>
      <form method="post" class="actions">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
        <input type="hidden" name="blueprint" value="<?= Security::e((string) $bp['key']) ?>">
        <button type="submit"><?= $isCurrent ? 'همگام‌سازی مجدد این کیت' : 'اعمال این کیت روی سایت' ?></button>
      </form>
    </article>
  <?php endforeach; ?>
</section>
