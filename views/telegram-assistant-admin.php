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
$businessConnections = is_array($businessConnections ?? null) ? $businessConnections : [];
$miniAppUrl = filter_var((string)($miniAppUrl ?? ''), FILTER_VALIDATE_URL) !== false ? (string)$miniAppUrl : '';
$preview = (bool)($preview ?? false);
$key = preg_match('/^[a-f0-9]{32}$/', (string)($key ?? '')) === 1 ? (string)$key : '';
$csrf = (string)($csrf ?? Security::csrf());
$assistant = is_array($profile['assistant'] ?? null) ? $profile['assistant'] : $profile;
$business = is_array($profile['business'] ?? null) ? $profile['business'] : [];
$settings = is_array($profile['settings'] ?? null) ? $profile['settings'] : [];
$autoReplyEnabled = !empty($settings['auto_reply_enabled'] ?? $settings['auto_reply'] ?? false);
$handoffUnknownEnabled = !empty($settings['handoff_unknown_enabled'] ?? $settings['handoff_unknown'] ?? false);
$assistantName = trim((string)($assistant['name'] ?? '')) ?: $copy['app_title'];
$businessName = trim((string)($business['name'] ?? '')) ?: $copy['admin_title'];
$initial = [
    'mode'=>'admin', 'key'=>$key, 'locale'=>$locale, 'direction'=>TelegramAssistantLocale::direction($locale),
    'csrf'=>$csrf, 'preview'=>$preview, 'profile'=>$profile, 'services'=>$services, 'slots'=>$slots,
    'bookings'=>$bookings, 'conversations'=>$conversations, 'business_connections'=>$businessConnections,
    'mini_app_url'=>$miniAppUrl, 'copy'=>$copy,
];
?>
<!doctype html>
<html lang="<?=Security::e($locale)?>" dir="<?=Security::e(TelegramAssistantLocale::direction($locale))?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title><?=Security::e($businessName)?></title>
  <link rel="stylesheet" href="/assets/telegram-assistant-1090.css?v=10.9.1">
  <script src="https://telegram.org/js/telegram-web-app.js?63"></script>
</head>
<body class="ta-body ta-admin-body" data-assistant-mode="admin">
  <a class="ta-skip" href="#ta-admin-main"><?=Security::e($copy['continue'])?></a>
  <div class="ta-app ta-admin-app" id="telegram-assistant-app">
    <header class="ta-topbar ta-admin-topbar">
      <div class="ta-identity"><span class="ta-avatar" aria-hidden="true">О</span><span><strong><?=Security::e($businessName)?></strong><small><?=Security::e($assistantName)?></small></span></div>
      <div class="ta-topbar-actions"><?php if ($preview): ?><span class="ta-preview-badge"><?=Security::e($copy['preview'])?></span><?php endif; ?><button class="ta-icon-button ta-notice-button" type="button" data-action="toggle-admin-notices" aria-label="<?=Security::e($copy['admin_notifications'])?>"><span aria-hidden="true">◇</span><b data-admin-notice-total hidden>0</b></button><button class="ta-icon-button" type="button" data-action="refresh-admin" aria-label="<?=Security::e($copy['refresh'])?>">↻</button></div>
    </header>
    <?php if ($preview): ?><div class="ta-preview-note" role="status"><span aria-hidden="true">◉</span><?=Security::e($copy['preview_notice'])?></div><?php endif; ?>
    <section class="ta-admin-notice-center" data-admin-notice-center aria-labelledby="ta-admin-notices-title" hidden>
      <header><h2 id="ta-admin-notices-title"><?=Security::e($copy['admin_notifications'])?></h2><button class="ta-text-button" type="button" data-action="close-admin-notices"><?=Security::e($copy['close'])?></button></header>
      <div data-admin-notice-list aria-live="polite"></div>
    </section>

    <nav class="ta-admin-tabs" aria-label="<?=Security::e($copy['admin_title'])?>">
      <button type="button" class="is-active" data-admin-navigate="inbox" aria-current="page"><span aria-hidden="true">◌</span><?=Security::e($copy['admin_inbox'])?><b data-unread-total hidden>0</b></button>
      <button type="button" data-admin-navigate="reservations"><span aria-hidden="true">◫</span><?=Security::e($copy['admin_reservations'])?></button>
      <button type="button" data-admin-navigate="services"><span aria-hidden="true">✦</span><?=Security::e($copy['admin_services'])?></button>
      <button type="button" data-admin-navigate="slots"><span aria-hidden="true">⌁</span><?=Security::e($copy['admin_slots'])?></button>
      <button type="button" data-admin-navigate="settings"><span aria-hidden="true">⚙</span><?=Security::e($copy['admin_settings'])?></button>
    </nav>

    <main class="ta-main ta-admin-main" id="ta-admin-main" tabindex="-1">
      <section class="ta-admin-view is-active" data-admin-view="inbox" aria-labelledby="ta-inbox-title" tabindex="-1">
        <header class="ta-section-heading ta-admin-section-heading"><div><span class="ta-kicker"><?=Security::e($copy['admin_title'])?></span><h1 id="ta-inbox-title"><?=Security::e($copy['admin_inbox'])?></h1></div><div class="ta-segmented" data-inbox-filter><button type="button" class="is-active" data-filter="all"><?=Security::e($copy['all'])?></button><button type="button" data-filter="waiting_human"><?=Security::e($copy['waiting_human'])?></button></div></header>
        <div class="ta-inbox-layout">
          <div class="ta-conversation-list" data-conversation-list aria-live="polite"></div>
          <article class="ta-conversation-panel" data-conversation-panel hidden>
            <header><button class="ta-icon-button ta-mobile-only" type="button" data-action="close-conversation" aria-label="<?=Security::e($copy['back'])?>">←</button><div><h2 data-conversation-name></h2><p data-conversation-status></p></div><button class="ta-text-button" type="button" data-action="toggle-takeover"></button></header>
            <div class="ta-message-list" data-message-list aria-live="polite"></div>
            <form class="ta-reply-form" data-reply-form>
              <label class="ta-sr-only" for="ta-reply-text"><?=Security::e($copy['reply'])?></label>
              <textarea id="ta-reply-text" name="text" rows="2" maxlength="4000" placeholder="<?=Security::e($copy['reply_placeholder'])?>"<?=$preview?' disabled':''?>></textarea>
              <button class="ta-button ta-button-primary" type="submit"<?=$preview?' disabled aria-disabled="true"':''?>><?=Security::e($copy['send'])?></button>
            </form>
            <button class="ta-text-button ta-close-conversation" type="button" data-action="mark-conversation-closed"<?=$preview?' disabled':''?>><?=Security::e($copy['mark_closed'])?></button>
          </article>
        </div>
      </section>

      <section class="ta-admin-view" data-admin-view="reservations" aria-labelledby="ta-admin-reservations-title" tabindex="-1" hidden>
        <header class="ta-section-heading ta-admin-section-heading"><div><span class="ta-kicker"><?=Security::e($copy['assistant'])?></span><h1 id="ta-admin-reservations-title"><?=Security::e($copy['admin_reservations'])?></h1></div><div class="ta-segmented" data-reservation-filter><button type="button" class="is-active" data-filter="today"><?=Security::e($copy['today'])?></button><button type="button" data-filter="upcoming"><?=Security::e($copy['upcoming'])?></button><button type="button" data-filter="all"><?=Security::e($copy['all'])?></button></div></header>
        <div class="ta-admin-card-list" data-admin-reservations aria-live="polite"></div>
      </section>

      <section class="ta-admin-view" data-admin-view="services" aria-labelledby="ta-admin-services-title" tabindex="-1" hidden>
        <header class="ta-section-heading ta-admin-section-heading"><div><span class="ta-kicker"><?=Security::e($copy['assistant'])?></span><h1 id="ta-admin-services-title"><?=Security::e($copy['admin_services'])?></h1></div><button class="ta-button ta-button-secondary ta-compact" type="button" data-action="new-service"<?=$preview?' disabled':''?>>+ <?=Security::e($copy['add_service'])?></button></header>
        <div class="ta-admin-card-list" data-admin-services aria-live="polite"></div>
        <form class="ta-admin-form ta-panel" data-service-form hidden>
          <input type="hidden" name="id">
          <label><span><?=Security::e($copy['service_name'])?></span><input name="title" maxlength="190" required></label>
          <label><span><?=Security::e($copy['service_summary'])?></span><textarea name="summary" maxlength="500" rows="3"></textarea></label>
          <div class="ta-form-grid"><label><span><?=Security::e($copy['duration_minutes'])?></span><input name="duration_minutes" type="number" min="10" max="480" step="5" required></label><label><span><?=Security::e($copy['price'])?></span><input name="price_amount" inputmode="decimal" type="number" min="0" step="0.01"></label><label><span><?=Security::e($copy['currency'])?></span><input name="currency" maxlength="8" value="RUB"></label></div>
          <label class="ta-check"><input name="enabled" type="checkbox" value="1" checked><span><?=Security::e($copy['enabled'])?></span></label>
          <div class="ta-form-actions"><button class="ta-button ta-button-primary" type="submit"<?=$preview?' disabled':''?>><?=Security::e($copy['save'])?></button><button class="ta-button ta-button-ghost" type="button" data-action="cancel-service-edit"><?=Security::e($copy['cancel'])?></button></div>
        </form>
      </section>

      <section class="ta-admin-view" data-admin-view="slots" aria-labelledby="ta-admin-slots-title" tabindex="-1" hidden>
        <header class="ta-section-heading ta-admin-section-heading"><div><span class="ta-kicker"><?=Security::e($copy['timezone'])?></span><h1 id="ta-admin-slots-title"><?=Security::e($copy['admin_slots'])?></h1></div><button class="ta-button ta-button-secondary ta-compact" type="button" data-action="new-slot"<?=$preview?' disabled':''?>>+ <?=Security::e($copy['add_slot'])?></button></header>
        <section class="ta-panel ta-schedule-panel" aria-labelledby="ta-weekly-schedule-title">
          <header class="ta-section-heading"><span class="ta-kicker"><?=Security::e($copy['admin_slots'])?></span><h2 id="ta-weekly-schedule-title"><?=Security::e($copy['weekly_schedule_title'])?></h2><p><?=Security::e($copy['weekly_schedule_help'])?></p></header>
          <form class="ta-admin-form" data-availability-form>
            <label><span><?=Security::e($copy['service'])?></span><select name="service_id" data-availability-service required></select></label>
            <div class="ta-weekly-rule-list" data-weekly-rule-list></div>
            <div class="ta-form-actions"><button class="ta-button ta-button-secondary" type="button" data-action="add-availability-window"<?=$preview?' disabled':''?>>+ <?=Security::e($copy['add_time_window'])?></button><button class="ta-button ta-button-primary" type="submit"<?=$preview?' disabled':''?>><?=Security::e($copy['save_schedule'])?></button></div>
          </form>
        </section>
        <section class="ta-panel ta-schedule-panel" aria-labelledby="ta-generate-slots-title">
          <header class="ta-section-heading"><h2 id="ta-generate-slots-title"><?=Security::e($copy['generate_slots_title'])?></h2><p><?=Security::e($copy['generate_slots_help'])?></p></header>
          <form class="ta-admin-form" data-slot-generation-form>
            <label><span><?=Security::e($copy['service'])?></span><select name="service_id" data-generation-service required></select></label>
            <div class="ta-form-grid"><label><span><?=Security::e($copy['from_date'])?></span><input name="from_date" type="date" required></label><label><span><?=Security::e($copy['until_date'])?></span><input name="until_date" type="date" required></label></div>
            <button class="ta-button ta-button-primary" type="submit"<?=$preview?' disabled':''?>><?=Security::e($copy['generate_slots'])?></button>
            <p class="ta-generation-result" data-slot-generation-result role="status" hidden></p>
          </form>
        </section>
        <div class="ta-admin-card-list" data-admin-slots aria-live="polite"></div>
        <form class="ta-admin-form ta-panel" data-slot-form hidden>
          <label><span><?=Security::e($copy['service'])?></span><select name="service_id" required></select></label>
          <div class="ta-form-grid"><label><span><?=Security::e($copy['slot_start'])?></span><input name="start_at" type="datetime-local" required></label><label><span><?=Security::e($copy['slot_end'])?></span><input name="end_at" type="datetime-local" required></label></div>
          <div class="ta-form-actions"><button class="ta-button ta-button-primary" type="submit"<?=$preview?' disabled':''?>><?=Security::e($copy['save'])?></button><button class="ta-button ta-button-ghost" type="button" data-action="cancel-slot-edit"><?=Security::e($copy['cancel'])?></button></div>
        </form>
      </section>

      <section class="ta-admin-view" data-admin-view="settings" aria-labelledby="ta-admin-settings-title" tabindex="-1" hidden>
        <header class="ta-section-heading"><span class="ta-kicker"><?=Security::e($copy['assistant'])?></span><h1 id="ta-admin-settings-title"><?=Security::e($copy['admin_settings'])?></h1></header>
        <form class="ta-admin-form ta-panel" data-settings-form>
          <label><span><?=Security::e($copy['settings_name'])?></span><input name="name" maxlength="120" value="<?=Security::e($assistantName)?>"></label>
          <label><span><?=Security::e($copy['settings_welcome'])?></span><textarea name="welcome" rows="4" maxlength="1000"><?=Security::e((string)($assistant['welcome']??''))?></textarea></label>
          <label><span><?=Security::e($copy['settings_timezone'])?></span><input name="timezone" maxlength="80" value="<?=Security::e((string)($business['timezone']??'Asia/Novosibirsk'))?>" dir="ltr"></label>
          <label class="ta-check"><input name="profile_enabled" type="checkbox" value="1"<?=!empty($settings['profile_enabled'])?' checked':''?>><span><?=Security::e($copy['settings_profile_enabled'])?></span></label>
          <label class="ta-check"><input name="auto_reply" type="checkbox" value="1"<?=$autoReplyEnabled?' checked':''?>><span><?=Security::e($copy['settings_auto_reply'])?></span></label>
          <label class="ta-check"><input name="handoff_unknown" type="checkbox" value="1"<?=$handoffUnknownEnabled?' checked':''?>><span><?=Security::e($copy['settings_handoff'])?></span></label>
          <button class="ta-button ta-button-primary" type="submit"<?=$preview?' disabled aria-disabled="true"':''?>><?=Security::e($copy['save'])?></button>
        </form>
        <section class="ta-admin-form ta-panel" aria-labelledby="ta-mini-app-url-title">
          <h2 id="ta-mini-app-url-title"><?=Security::e($copy['mini_app_url_title'])?></h2>
          <p><?=Security::e($copy['mini_app_url_help'])?></p>
          <label><span><?=Security::e($copy['mini_app_url_label'])?></span><input type="url" dir="ltr" readonly data-mini-app-url value="<?=Security::e($miniAppUrl)?>"></label>
          <button class="ta-button ta-button-secondary" type="button" data-action="copy-mini-app-url"<?=$miniAppUrl===''?' disabled aria-disabled="true"':''?>><?=Security::e($copy['copy_url'])?></button>
        </section>
        <section class="ta-panel" aria-labelledby="ta-business-connections-title">
          <header class="ta-section-heading"><span class="ta-kicker">Telegram Business</span><h2 id="ta-business-connections-title"><?=Security::e($copy['business_connections_title'])?></h2></header>
          <p><?=Security::e($copy['business_connections_help'])?></p>
          <div class="ta-admin-card-list" data-business-connections aria-live="polite"></div>
        </section>
      </section>
    </main>
  </div>
  <div class="ta-toast" data-toast role="status" aria-live="polite" hidden></div>
  <div class="ta-loading" data-loading aria-live="polite" hidden><span class="ta-spinner" aria-hidden="true"></span><span data-loading-text><?=Security::e($copy['loading'])?></span></div>
  <script type="application/json" id="telegram-assistant-state" nonce="<?=Security::e(Security::cspNonce())?>"><?=json_encode($initial, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
  <script src="/assets/telegram-assistant-1090.js?v=10.9.1" defer></script>
</body>
</html>
