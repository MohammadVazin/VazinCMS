<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">ماژول رسمی Schema v2 · بدون کوکی و حافظ حریم خصوصی</span>
    <h1>آمار بازدید و آنالیتیکس بومی (Vazin Analytics)</h1>
    <p>تحلیل سریع ترافیک سایت بدون اسکریپت‌های ردیاب خارجی و بدون افت سرعت Core Web Vitals.</p>
  </div>
</section>

<section class="cms-kpi-grid" style="margin-bottom:24px;">
  <article class="cms-kpi">
    <div class="cms-kpi-top"><span>کل بازدید صفحات</span></div>
    <strong><?= number_format((int) ($report['total_views'] ?? 0)) ?></strong>
    <small>بازدیدهای واقعی کاربران</small>
  </article>
  <article class="cms-kpi">
    <div class="cms-kpi-top"><span>بازدیدکنندگان یکتا</span></div>
    <strong><?= number_format((int) ($report['unique_visitors'] ?? 0)) ?></strong>
    <small>هش روزانه بدون کوکی</small>
  </article>
  <article class="cms-kpi">
    <div class="cms-kpi-top"><span>موبایل</span></div>
    <strong><?= number_format((int) ($report['devices']['mobile'] ?? 0)) ?></strong>
    <small>کاربران تلفن همراه</small>
  </article>
  <article class="cms-kpi">
    <div class="cms-kpi-top"><span>دسکتاپ</span></div>
    <strong><?= number_format((int) ($report['devices']['desktop'] ?? 0)) ?></strong>
    <small>کاربران رایانه</small>
  </article>
</section>

<section class="card">
  <h2>پربازدیدترین صفحات</h2>
  <?php if (empty($report['top_pages'])): ?>
    <p>هنوز بازدیدی ثبت نشده است.</p>
  <?php else: ?>
    <div class="cms-content-items">
      <?php foreach ($report['top_pages'] as $pagePath => $viewsCount): ?>
        <div class="cms-content-item">
          <span class="cms-content-text"><strong dir="ltr"><?= Security::e((string) $pagePath) ?></strong></span>
          <span class="badge"><?= number_format((int) $viewsCount) ?> بازدید</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
