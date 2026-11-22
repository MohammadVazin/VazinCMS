<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">ماژول رسمی Schema v2 · صف تأیید و امتیازدهی</span>
    <h1>مدیریت دیدگاه‌ها و امتیازات (Vazin Comments)</h1>
    <p>بررسی، تأیید یا رد دیدگاه‌ها و امتیازهای ثبت‌شده روی نوشته‌ها، محصولات و دوره‌ها.</p>
  </div>
  <div class="title-actions">
    <span class="metric"><b><?= count($comments) ?></b> کل دیدگاه‌ها</span>
  </div>
</section>

<?php if (!empty($message)): ?><div class="alert success"><?= Security::e((string) $message) ?></div><?php endif; ?>

<section class="card">
  <?php if ($comments === []): ?>
    <p>هنوز دیدگاهی ثبت نشده است.</p>
  <?php else: ?>
    <div class="cms-content-items">
      <?php foreach ($comments as $c): ?>
        <div class="cms-content-item" style="align-items:flex-start;">
          <span class="cms-content-text">
            <strong><?= Security::e((string) $c['author_name']) ?> · <code dir="ltr"><?= Security::e((string) $c['target_type'] . ':' . (string) $c['target_key']) ?></code></strong>
            <p style="margin:6px 0;"><?= Security::e((string) $c['body']) ?></p>
            <small>وضعیت: <strong><?= Security::e((string) $c['status']) ?></strong><?= $c['rating'] !== null ? ' · امتیاز: ' . (int) $c['rating'] . '/5' : '' ?></small>
          </span>
          <div class="actions">
            <?php if (($c['status'] ?? '') !== 'approved'): ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
                <input type="hidden" name="comment_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="status" value="approved">
                <button type="submit">تأیید</button>
              </form>
            <?php endif; ?>
            <?php if (($c['status'] ?? '') !== 'rejected'): ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
                <input type="hidden" name="comment_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="status" value="rejected">
                <button type="submit" class="danger">رد</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
