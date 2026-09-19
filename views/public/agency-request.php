<?php
require __DIR__ . '/head.php';

use VazinCMS\Security;
?>
<div class="application-shell">
  <?php if ($submitted): ?>
    <section class="application-card">
      <span class="platform-eyebrow">✓</span>
      <h1><?=Security::e($agencyCopy['success_title'])?></h1>
      <p><?=Security::e($agencyCopy['success_text'])?></p>
      <div class="platform-inline-actions">
        <a class="primary" href="/<?=Security::e($locale)?>"><?=Security::e($locale === 'ru' ? 'На главную' : ($locale === 'en' ? 'Back to home' : 'بازگشت به صفحهٔ اصلی'))?></a>
      </div>
    </section>
  <?php else: ?>
    <section class="application-card">
      <span class="platform-eyebrow"><?=Security::e($agencyCopy['eyebrow'])?></span>
      <h1><?=Security::e($agencyCopy['title'])?></h1>
      <p><?=Security::e($agencyCopy['text'])?></p>
      <?php if ($error): ?><div class="platform-alert error" role="alert"><?=Security::e($error)?></div><?php endif; ?>
      <form class="application-form" method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <fieldset class="application-fieldset">
          <legend><?=Security::e($agencyCopy['form_title'])?></legend>
          <p class="platform-note"><?=Security::e($agencyCopy['form_text'])?></p>
          <div class="application-fields">
            <label><span><?=Security::e($locale === 'ru' ? 'Название агентства' : ($locale === 'en' ? 'Agency or business name' : 'نام آژانس یا کسب‌وکار'))?></span><input name="organization" maxlength="160" required autocomplete="organization" value="<?=Security::e($form['organization'])?>"></label>
            <label><span><?=Security::e($locale === 'ru' ? 'Контактное лицо' : ($locale === 'en' ? 'Contact person' : 'نام شخص تماس'))?></span><input name="contact_name" maxlength="160" required autocomplete="name" value="<?=Security::e($form['contact_name'])?>"></label>
            <label><span><?=Security::e($locale === 'ru' ? 'Рабочий email' : ($locale === 'en' ? 'Work email' : 'ایمیل کاری'))?></span><input type="email" name="email" maxlength="190" required autocomplete="email" value="<?=Security::e($form['email'])?>"></label>
            <label><span><?=Security::e($locale === 'ru' ? 'Телефон' : ($locale === 'en' ? 'Phone' : 'شماره تماس'))?></span><input class="ltr-value" dir="ltr" inputmode="tel" name="phone" maxlength="64" required autocomplete="tel" value="<?=Security::e($form['phone'])?>"></label>
            <label><span><?=Security::e($locale === 'ru' ? 'Страна работы' : ($locale === 'en' ? 'Operating country' : 'کشور فعالیت'))?></span><input name="country" maxlength="100" required autocomplete="country-name" value="<?=Security::e($form['country'])?>"></label>
            <label><span><?=Security::e($locale === 'ru' ? 'Сайт (необязательно)' : ($locale === 'en' ? 'Website (optional)' : 'وب‌سایت (اختیاری)'))?></span><input class="ltr-value" dir="ltr" type="url" name="website" maxlength="2048" autocomplete="url" placeholder="https://…" value="<?=Security::e($form['website'])?>"></label>
          </div>
        </fieldset>

        <fieldset class="application-fieldset">
          <legend><?=Security::e($locale === 'ru' ? 'Направления' : ($locale === 'en' ? 'Service areas' : 'حوزه‌های خدمت'))?></legend>
          <div class="application-fields">
            <?php foreach ($serviceOptions as $value => $label): ?>
              <label class="application-consent"><input type="checkbox" name="services[]" value="<?=Security::e($value)?>" <?=in_array($value, $form['services'], true) ? 'checked' : ''?>><span><?=Security::e($label)?></span></label>
            <?php endforeach; ?>
            <label class="wide"><span><?=Security::e($locale === 'ru' ? 'Контекст или цели (необязательно)' : ($locale === 'en' ? 'Context or goals (optional)' : 'زمینه یا هدف همکاری (اختیاری)'))?></span><textarea name="notes" maxlength="2000"><?=Security::e($form['notes'])?></textarea></label>
          </div>
        </fieldset>

        <label class="application-consent"><input type="checkbox" name="privacy_consent" value="1" required <?=((string) ($_POST['privacy_consent'] ?? '') === '1') ? 'checked' : ''?>><span><?=Security::e($agencyCopy['consent'])?></span></label>
        <div class="platform-inline-actions">
          <button class="primary" type="submit"><?=Security::e($agencyCopy['submit'])?></button>
        </div>
      </form>
    </section>

    <aside class="platform-form-card" aria-label="<?=Security::e($agencyCopy['side_title'])?>">
      <span class="platform-kicker">01</span>
      <h2><?=Security::e($agencyCopy['side_title'])?></h2>
      <div class="platform-grid">
        <?php foreach ($agencyCopy['side_items'] as $index => $item): ?>
          <article class="platform-card"><strong><?=sprintf('%02d', $index + 1)?></strong><p><?=Security::e($item)?></p></article>
        <?php endforeach; ?>
      </div>
    </aside>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/foot.php'; ?>
