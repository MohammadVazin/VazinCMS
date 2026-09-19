<?php
declare(strict_types=1);

use VazinCMS\{Security, TelegramAssistantLocale};

$locale = TelegramAssistantLocale::normalize((string)($locale ?? 'fa'));
$copy = is_array($copy ?? null) ? array_replace(TelegramAssistantLocale::all($locale), $copy) : TelegramAssistantLocale::all($locale);
$profile = is_array($profile ?? null) ? $profile : [];
$services = is_array($services ?? null) ? $services : [];
$slots = is_array($slots ?? null) ? $slots : [];
$bookings = is_array($bookings ?? null) ? $bookings : [];
$conversations = is_array($conversations ?? null) ? $conversations : [];
$preview = (bool)($preview ?? false);
$key = preg_match('/^[a-f0-9]{32}$/', (string)($key ?? '')) === 1 ? (string)$key : '';
$csrf = (string)($csrf ?? Security::csrf());
$assistant = is_array($profile['assistant'] ?? null) ? $profile['assistant'] : $profile;
$customer = is_array($profile['customer'] ?? null) ? $profile['customer'] : [];
$capabilities = is_array($profile['capabilities'] ?? null) ? $profile['capabilities'] : [];
$capabilityEnabled = static function (string $name) use ($capabilities): bool {
    if (!array_key_exists($name, $capabilities)) return true;
    return filter_var($capabilities[$name], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool)$capabilities[$name];
};
$bookingEnabled = $capabilityEnabled('booking');
$handoffEnabled = $capabilityEnabled('handoff');
$assistantName = trim((string)($assistant['name'] ?? '')) ?: $copy['app_title'];
$welcome = trim((string)($assistant['welcome'] ?? '')) ?: $copy['welcome_lead'];
$avatar = trim((string)($assistant['avatar_url'] ?? ''));
$initial = [
    'mode'=>'customer', 'key'=>$key, 'locale'=>$locale, 'direction'=>TelegramAssistantLocale::direction($locale),
    'csrf'=>$csrf, 'preview'=>$preview, 'profile'=>$profile, 'services'=>$services, 'slots'=>$slots,
    'bookings'=>$bookings, 'conversations'=>$conversations, 'copy'=>$copy,
];
?>
<!doctype html>
<html lang="<?=Security::e($locale)?>" dir="<?=Security::e(TelegramAssistantLocale::direction($locale))?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title><?=Security::e($assistantName)?></title>
  <link rel="stylesheet" href="/assets/telegram-assistant-1090.css?v=10.9.1">
  <script src="https://telegram.org/js/telegram-web-app.js?63"></script>
</head>
<body class="ta-body" data-assistant-mode="customer">
  <a class="ta-skip" href="#ta-main"><?=Security::e($copy['continue'])?></a>
  <div class="ta-app" id="telegram-assistant-app">
    <header class="ta-topbar">
      <div class="ta-identity">
        <?php if ($avatar !== '' && (str_starts_with($avatar, '/') || filter_var($avatar, FILTER_VALIDATE_URL))): ?>
          <img src="<?=Security::e($avatar)?>" alt="" width="44" height="44" referrerpolicy="no-referrer">
        <?php else: ?>
          <span class="ta-avatar" aria-hidden="true">О</span>
        <?php endif; ?>
        <span><strong><?=Security::e($assistantName)?></strong><small><?=Security::e($copy['assistant'])?></small></span>
      </div>
      <?php if ($preview): ?><span class="ta-preview-badge"><?=Security::e($copy['preview'])?></span><?php endif; ?>
    </header>

    <?php if ($preview): ?>
      <div class="ta-preview-note" role="status"><span aria-hidden="true">◉</span><?=Security::e($copy['preview_notice'])?></div>
    <?php endif; ?>

    <main class="ta-main" id="ta-main" tabindex="-1">
      <section class="ta-view is-active" data-view="home" aria-labelledby="ta-home-title" tabindex="-1">
        <div class="ta-welcome-card">
          <span class="ta-kicker"><?=Security::e($assistantName)?></span>
          <h1 id="ta-home-title" data-greeting><?=Security::e(!empty($customer['first_name'])
              ? str_replace('{name}', (string)$customer['first_name'], $copy['hello'])
              : $copy['hello_guest'])?></h1>
          <p><?=Security::e($welcome)?></p>
        </div>
        <div class="ta-quick-grid" aria-label="<?=Security::e($copy['nav_home'])?>">
          <button class="ta-action-card is-primary" type="button" data-action="start-booking" data-capability="booking"<?=!$bookingEnabled?' hidden disabled aria-disabled="true"':''?>>
            <span class="ta-action-icon" aria-hidden="true">⌁</span><span><strong><?=Security::e($copy['quick_book'])?></strong><small><?=Security::e($copy['quick_book_desc'])?></small></span>
          </button>
          <button class="ta-action-card" type="button" data-navigate="services">
            <span class="ta-action-icon" aria-hidden="true">✦</span><span><strong><?=Security::e($copy['quick_services'])?></strong><small><?=Security::e($copy['quick_services_desc'])?></small></span>
          </button>
          <button class="ta-action-card" type="button" data-action="open-chat">
            <span class="ta-action-icon" aria-hidden="true">◌</span><span><strong><?=Security::e($copy['quick_question'])?></strong><small><?=Security::e($copy['quick_question_desc'])?></small></span>
          </button>
          <button class="ta-action-card" type="button" data-action="handoff" data-capability="handoff"<?=!$handoffEnabled?' hidden disabled aria-disabled="true"':''?>>
            <span class="ta-action-icon" aria-hidden="true">↗</span><span><strong><?=Security::e($copy['quick_oksana'])?></strong><small><?=Security::e($copy['quick_oksana_desc'])?></small></span>
          </button>
        </div>
      </section>

      <section class="ta-view" data-view="services" aria-labelledby="ta-services-title" tabindex="-1" hidden>
        <header class="ta-section-heading"><span class="ta-kicker"><?=Security::e($copy['assistant'])?></span><h1 id="ta-services-title"><?=Security::e($copy['services_title'])?></h1><p><?=Security::e($copy['services_lead'])?></p></header>
        <div class="ta-card-list" data-services-list aria-live="polite"></div>
      </section>

      <section class="ta-view" data-view="booking" aria-labelledby="ta-booking-title" tabindex="-1" hidden>
        <header class="ta-section-heading ta-booking-heading"><div><span class="ta-kicker" data-step-counter><?=Security::e(str_replace(['{current}','{total}'], ['1','4'], $copy['step_of']))?></span><h1 id="ta-booking-title"><?=Security::e($copy['booking_title'])?></h1></div><button class="ta-text-button" type="button" data-booking-back hidden><?=Security::e($copy['back'])?></button></header>
        <ol class="ta-stepper" aria-label="<?=Security::e($copy['booking_title'])?>">
          <?php foreach (['step_service','step_slot','step_details','step_confirm'] as $index=>$label): ?><li<?=$index===0?' class="is-current" aria-current="step"':''?> data-step-marker="<?=$index+1?>"><span><?=$index+1?></span><small><?=Security::e($copy[$label])?></small></li><?php endforeach; ?>
        </ol>

        <div class="ta-booking-step" data-booking-step="1" tabindex="-1">
          <h2><?=Security::e($copy['choose_service'])?></h2><p><?=Security::e($copy['choose_service_help'])?></p>
          <div class="ta-choice-list" data-booking-services></div>
        </div>
        <div class="ta-booking-step" data-booking-step="2" tabindex="-1" hidden>
          <h2><?=Security::e($copy['choose_date'])?></h2>
          <div class="ta-date-strip" data-date-strip role="list"></div>
          <div class="ta-slot-heading"><h3><?=Security::e($copy['choose_time'])?></h3><small data-timezone></small></div>
          <div class="ta-slot-grid" data-slot-list aria-live="polite"></div>
        </div>
        <form class="ta-booking-step ta-form" data-booking-step="3" data-details-form tabindex="-1" hidden novalidate>
          <h2><?=Security::e($copy['your_details'])?></h2>
          <label><span><?=Security::e($copy['name'])?> *</span><input name="name" autocomplete="name" maxlength="120" required value="<?=Security::e(trim((string)(($customer['first_name']??'').' '.($customer['last_name']??''))))?>"><small class="ta-field-error" data-error-for="name"></small></label>
          <label><span><?=Security::e($copy['phone_optional'])?></span><input name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="40"><small class="ta-field-error" data-error-for="phone"></small></label>
          <label><span><?=Security::e($copy['email_optional'])?></span><input name="email" type="email" inputmode="email" autocomplete="email" maxlength="190"><small class="ta-field-error" data-error-for="email"></small></label>
          <label><span><?=Security::e($copy['notes'])?></span><textarea name="notes" rows="3" maxlength="500" placeholder="<?=Security::e($copy['notes_hint'])?>"></textarea></label>
          <p class="ta-privacy"><span aria-hidden="true">◇</span><?=Security::e($copy['privacy_note'])?></p>
          <button class="ta-button ta-button-primary" type="submit"><?=Security::e($copy['continue'])?></button>
        </form>
        <div class="ta-booking-step" data-booking-step="4" tabindex="-1" hidden>
          <h2><?=Security::e($copy['review_title'])?></h2>
          <dl class="ta-review" data-booking-review></dl>
          <button class="ta-button ta-button-primary" type="button" data-action="confirm-booking"<?=$preview?' disabled aria-disabled="true"':''?>><?=Security::e($copy['confirm_booking'])?></button>
        </div>
        <div class="ta-booking-step ta-success" data-booking-step="5" tabindex="-1" hidden>
          <span class="ta-success-mark" aria-hidden="true">✓</span><h2><?=Security::e($copy['booking_success'])?></h2><p><?=Security::e($copy['booking_success_text'])?></p><strong data-booking-reference></strong>
          <button class="ta-button ta-button-primary" type="button" data-navigate="bookings"><?=Security::e($copy['done'])?></button>
        </div>
      </section>

      <section class="ta-view" data-view="bookings" aria-labelledby="ta-bookings-title" tabindex="-1" hidden>
        <header class="ta-section-heading"><span class="ta-kicker"><?=Security::e($copy['assistant'])?></span><h1 id="ta-bookings-title"><?=Security::e($copy['my_bookings_title'])?></h1><p><?=Security::e($copy['my_bookings_lead'])?></p></header>
        <div class="ta-card-list" data-bookings-list aria-live="polite"></div>
        <button class="ta-button ta-button-secondary" type="button" data-action="start-booking" data-capability="booking"<?=!$bookingEnabled?' hidden disabled aria-disabled="true"':''?>><?=Security::e($copy['new_booking'])?></button>
      </section>
    </main>

    <nav class="ta-bottom-nav<?=$bookingEnabled?'':' is-booking-disabled'?>" aria-label="<?=Security::e($copy['nav_home'])?>">
      <button type="button" class="is-active" data-navigate="home" aria-current="page"><span aria-hidden="true">⌂</span><small><?=Security::e($copy['nav_home'])?></small></button>
      <button type="button" data-navigate="services"><span aria-hidden="true">✦</span><small><?=Security::e($copy['nav_services'])?></small></button>
      <button type="button" data-navigate="bookings" data-capability="booking"<?=!$bookingEnabled?' hidden disabled aria-disabled="true"':''?>><span aria-hidden="true">◫</span><small><?=Security::e($copy['nav_bookings'])?></small></button>
    </nav>
  </div>
  <div class="ta-toast" data-toast role="status" aria-live="polite" hidden></div>
  <div class="ta-loading" data-loading aria-live="polite" hidden><span class="ta-spinner" aria-hidden="true"></span><span data-loading-text><?=Security::e($copy['loading'])?></span></div>
  <script type="application/json" id="telegram-assistant-state" nonce="<?=Security::e(Security::cspNonce())?>"><?=json_encode($initial, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
  <script src="/assets/telegram-assistant-1090.js?v=10.9.1" defer></script>
</body>
</html>
