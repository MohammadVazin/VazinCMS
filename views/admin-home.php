<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<div class="cms-overview">
  <section class="cms-overview-intro" aria-labelledby="cms-overview-title">
    <div>
      <p class="cms-kicker">مدیریت سایت / پیشخوان</p>
      <h1 id="cms-overview-title">خوش آمدید، <?= Security::e((string)($user['name'] ?? 'مدیر')) ?></h1>
      <p>از اینجا محتوا، رسانه‌ها، قالب‌ها و تنظیمات سایت را در یک فضای یکپارچه مدیریت کنید.</p>
    </div>
    <div class="cms-overview-actions">
      <a class="cms-action-primary" href="/admin/pages">+ ایجاد صفحه</a>
      <a class="cms-action-secondary" href="/admin/pages?type=post">+ نوشته جدید</a>
    </div>
  </section>

  <section class="cms-kpi-grid" aria-label="آمار محتوای سایت">
    <?php foreach ([
      ['value' => $stats['total'], 'label' => 'کل محتوا', 'sub' => 'صفحه و نوشته', 'tag' => '01'],
      ['value' => $stats['published'], 'label' => 'منتشرشده', 'sub' => 'در دسترس بازدیدکنندگان', 'tag' => '02'],
      ['value' => $stats['drafts'], 'label' => 'پیش‌نویس‌ها', 'sub' => 'در انتظار انتشار', 'tag' => '03'],
      ['value' => $stats['media'], 'label' => 'فایل‌های رسانه', 'sub' => 'کتابخانه فعلی', 'tag' => '04'],
    ] as $kpi): ?>
    <article class="cms-kpi">
      <div class="cms-kpi-top"><span><?= Security::e($kpi['label']) ?></span><span class="cms-kpi-tag" aria-hidden="true"><?= $kpi['tag'] ?></span></div>
      <strong><?= number_format((int)$kpi['value']) ?></strong>
      <small><?= Security::e($kpi['sub']) ?></small>
    </article>
    <?php endforeach; ?>
  </section>

  <div class="cms-overview-columns">
    <section class="cms-overview-card cms-recent" aria-labelledby="cms-recent-heading">
      <div class="cms-card-heading">
        <div><h2 id="cms-recent-heading">آخرین محتوا</h2><p>محتوای ویرایش‌شده اخیر</p></div>
        <a href="/admin/pages">مشاهده همه <span aria-hidden="true">←</span></a>
      </div>
      <?php if ($recent): ?>
      <div class="cms-content-items">
        <?php foreach ($recent as $page): ?>
          <a class="cms-content-item" href="/admin/pages?edit=<?= (int)$page['id'] ?>">
            <span class="cms-content-avatar" aria-hidden="true"><?= Security::e(mb_substr(trim((string)$page['title']),0,1)) ?></span>
            <span class="cms-content-text">
              <strong><?= Security::e((string)$page['title']) ?></strong>
              <small><?= ($page['content_type'] ?? '') === 'post' ? 'نوشته' : 'صفحه' ?> · <?= Security::e(strtoupper((string)$page['locale'])) ?> · <?= Security::e((string)$page['updated_at']) ?></small>
            </span>
            <span class="cms-content-status <?= ($page['status'] ?? '') === 'published' ? 'is-published' : 'is-draft' ?>"><?= ($page['status'] ?? '') === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="cms-content-empty">
        <strong>هنوز محتوایی ایجاد نشده است.</strong>
        <p>برای آغاز، اولین صفحه سایت را بسازید.</p>
        <a href="/admin/pages">ساخت اولین صفحه</a>
      </div>
      <?php endif; ?>
    </section>

    <section class="cms-overview-card cms-shortcuts" aria-labelledby="cms-shortcuts-heading">
      <div class="cms-card-heading"><div><h2 id="cms-shortcuts-heading">دسترسی سریع</h2><p>ابزارهایی که بیشتر استفاده می‌کنید</p></div></div>
      <div class="cms-shortcut-list">
        <a href="/admin/pages"><span class="cms-shortcut-icon" aria-hidden="true">▤</span><span><strong>مدیریت صفحه‌ها</strong><small>ویرایش و انتشار محتوا</small></span><span aria-hidden="true">←</span></a>
        <a href="/admin/media"><span class="cms-shortcut-icon" aria-hidden="true">▧</span><span><strong>کتابخانه رسانه</strong><small>تصاویر و اسناد</small></span><span aria-hidden="true">←</span></a>
        <a href="/admin/menus"><span class="cms-shortcut-icon" aria-hidden="true">☷</span><span><strong>فهرست‌های سایت</strong><small>ناوبری و پیوندها</small></span><span aria-hidden="true">←</span></a>
        <a href="/admin/extensions"><span class="cms-shortcut-icon" aria-hidden="true">◇</span><span><strong>قالب و افزونه‌ها</strong><small>گسترش قابلیت‌های سایت</small></span><span aria-hidden="true">←</span></a>
      </div>
    </section>
  </div>

  <section class="cms-workspace-status" aria-labelledby="cms-site-fleet-title">
    <div>
      <span class="cms-workspace-status-dot" aria-hidden="true"></span>
      <div><h2 id="cms-site-fleet-title">مدیریت چندسایتی</h2><p>فضای این سایت فعال است؛ مدیریت هم‌زمان چند سایت تا تکمیل جداسازی مجوز و داده‌ها در مرحله آزمایشی می‌ماند.</p></div>
    </div>
    <span class="cms-workspace-status-tag">در حال توسعه</span>
  </section>
</div>
