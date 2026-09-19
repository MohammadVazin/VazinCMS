<?php
require __DIR__ . '/head.php';

use VazinCMS\Security;

$today = gmdate('Y-m-d');
?>
<div class="application-shell">
  <section class="application-card">
    <span class="platform-eyebrow"><?=Security::e($intakeCopy['eyebrow'])?></span>
    <h1><?=Security::e($intakeCopy['title'])?></h1>
    <p><?=Security::e($intakeCopy['text'])?></p>
    <?php if (($errors['_form'] ?? '') !== ''): ?>
      <div class="platform-alert error" role="alert"><?=Security::e((string) $errors['_form'])?></div>
    <?php endif; ?>
    <form class="application-form" method="post" novalidate>
      <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
      <fieldset class="application-fieldset">
        <legend><?=Security::e($intakeCopy['form_title'])?></legend>
        <p class="platform-note"><?=Security::e($intakeCopy['form_text'])?></p>
        <div class="application-fields">
          <label><span><?=Security::e($intakeCopy['name'])?></span><input name="full_name" maxlength="190" required autocomplete="name" value="<?=Security::e($form['full_name'])?>"></label>
          <label><span><?=Security::e($intakeCopy['phone'])?></span><input class="ltr-value" dir="ltr" inputmode="tel" name="phone" maxlength="64" required autocomplete="tel" value="<?=Security::e($form['phone'])?>"></label>
          <label><span><?=Security::e($intakeCopy['email'])?></span><input class="ltr-value" dir="ltr" type="email" name="email" maxlength="190" autocomplete="email" value="<?=Security::e($form['email'])?>"></label>
          <label><span><?=Security::e($intakeCopy['nationality'])?></span><input name="nationality" maxlength="100" required autocomplete="country-name" value="<?=Security::e($form['nationality'])?>"></label>
          <label><span><?=Security::e($intakeCopy['destination'])?></span><input name="destination" maxlength="100" required value="<?=Security::e($form['destination'])?>"></label>
          <label><span><?=Security::e($intakeCopy['date'])?></span><input class="ltr-value" dir="ltr" type="date" name="travel_date" min="<?=Security::e($today)?>" value="<?=Security::e($form['travel_date'])?>"></label>
          <label class="wide"><span><?=Security::e($intakeCopy['notes'])?></span><textarea name="notes" maxlength="1000" rows="4"><?=Security::e($form['notes'])?></textarea></label>
        </div>
      </fieldset>
      <label class="application-consent"><input type="checkbox" name="manual_review_consent" value="1" required <?=((string) ($_POST['manual_review_consent'] ?? '') === '1') ? 'checked' : ''?>><span><?=Security::e($intakeCopy['consent'])?></span></label>
      <div class="platform-inline-actions"><button class="primary" type="submit"><?=Security::e($intakeCopy['submit'])?></button></div>
    </form>
  </section>
  <aside class="platform-form-card" aria-label="<?=Security::e($intakeCopy['side_title'])?>">
    <span class="platform-kicker">01</span>
    <h2><?=Security::e($intakeCopy['side_title'])?></h2>
    <div class="platform-grid">
      <?php foreach ([$intakeCopy['side_one'], $intakeCopy['side_two'], $intakeCopy['side_three']] as $index => $item): ?>
        <article class="platform-card"><strong><?=sprintf('%02d', $index + 1)?></strong><p><?=Security::e($item)?></p></article>
      <?php endforeach; ?>
    </div>
  </aside>
</div>
<?php require __DIR__ . '/foot.php'; ?>
