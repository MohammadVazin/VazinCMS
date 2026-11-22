<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<main class="container" style="max-width:1120px;margin:0 auto;padding:32px 16px;">
  <header style="margin-bottom:28px;">
    <h1>آکادمی آموزش آنلاین <?= Security::e((string) ($siteName ?? '')) ?></h1>
    <p>دوره‌های مهارت‌محور همراه با گواهی پایان دوره.</p>
  </header>

  <?php if (!empty($enrollSuccess)): ?>
    <div class="alert success" style="margin-bottom:20px;"><?= Security::e((string) $enrollSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($enrollError)): ?>
    <div class="alert error" style="margin-bottom:20px;"><?= Security::e((string) $enrollError) ?></div>
  <?php endif; ?>

  <?php if ($courses === []): ?>
    <p>هنوز دوره آموزشی منتشرشده‌ای ثبت نشده است.</p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
      <?php foreach ($courses as $c): ?>
        <article class="card" style="padding:22px;border-radius:16px;border:1px solid #dce3ee;">
          <h2><?= Security::e((string) $c['title']) ?></h2>
          <p><?= Security::e((string) $c['summary']) ?></p>
          <p><small>مدرس: <?= Security::e((string) $c['instructor']) ?> · مدت: <?= (int) $c['duration_hours'] ?> ساعت · سطح: <?= Security::e((string) $c['level']) ?></small></p>
          <form method="post" action="/courses">
            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
            <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
            <label>نام دانشجو<input name="student_name" required></label>
            <label>ایمیل دانشجو<input type="email" name="student_email" required></label>
            <button type="submit">ثبت‌نام در دوره</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
