<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="admin-title">
  <div>
    <span class="eyebrow">ماژول رسمی Schema v2 · آموزش آنلاین</span>
    <h1>مدیریت دوره‌ها و آموزشگاه (Vazin LMS)</h1>
    <p>دوره‌های آموزشی، سرفصل‌ها، دروس و ثبت‌نام دانشجویان را یکپارچه مدیریت کنید.</p>
  </div>
  <div class="title-actions">
    <span class="metric"><b><?= count($courses) ?></b> دوره آموزشی</span>
    <a class="button secondary" href="/courses" target="_blank" rel="noopener">مشاهده آکادمی ↗</a>
  </div>
</section>

<?php if ($message !== ''): ?><div class="alert success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>

<section class="grid two">
  <article class="card">
    <h2>ایجاد دوره جدید</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
      <input type="hidden" name="action" value="create_course">
      <label>عنوان دوره<input name="title" required placeholder="مثلاً: مهندسی نرم‌افزار مدرن"></label>
      <label>نام مدرس<input name="instructor" value="تیم آموزشی"></label>
      <label>سطح دوره
        <select name="level">
          <option value="beginner">مقدماتی</option>
          <option value="intermediate">متوسط</option>
          <option value="advanced">پیشرفته</option>
        </select>
      </label>
      <label>مدت دوره (ساعت)<input type="number" min="1" name="duration_hours" value="10"></label>
      <label>شهریه (۰ = رایگان)<input type="number" step="0.01" min="0" name="price" value="0"></label>
      <label>معرفی دوره<textarea name="summary" rows="3"></textarea></label>
      <button type="submit">انتشار دوره آموزشی</button>
    </form>
  </article>

  <article class="card">
    <h2>افزودن درس به سرفصل دوره</h2>
    <?php if ($courses): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
        <input type="hidden" name="action" value="add_lesson">
        <label>انتخاب دوره
          <select name="course_id">
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= Security::e((string) $c['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>عنوان درس<input name="title" required placeholder="فصل اول: معماری پایه"></label>
        <label>مدت زمان (دقیقه)<input type="number" min="1" name="duration_minutes" value="15"></label>
        <label>لینک ویدیو (اختیاری)<input name="video_url" dir="ltr" placeholder="https://..."></label>
        <label>متن و توضیحات درس<textarea name="content" rows="3"></textarea></label>
        <button type="submit">افزودن درس</button>
      </form>
    <?php else: ?>
      <p>ابتدا اولین دوره آموزشی را بسازید تا بتوانید دروس آن را اضافه کنید.</p>
    <?php endif; ?>
  </article>
</section>
