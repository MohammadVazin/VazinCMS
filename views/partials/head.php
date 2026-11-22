<?php
use VazinCMS\Security;

$identityTheme = is_array($currentUser) && in_array(($currentUser['identity_theme'] ?? null), ['system','light','dark'],true)
    ? (string)$currentUser['identity_theme'] : 'system';
$identityName = is_array($currentUser) ? trim((string)($currentUser['identity_display_name'] ?? '')) : '';
$displayName = $identityName !== '' ? $identityName : (is_array($currentUser) ? (string)($currentUser['name'] ?? '') : '');
$identityPicture = is_array($currentUser) ? (string)($currentUser['identity_picture_url'] ?? '') : '';
$moduleNavigation = $currentUser ? \VazinCMS\ExtensionManager::adminNavigation() : [];
?>
<!doctype html>
<html lang="<?= Security::e(\VazinCMS\UiLocale::locale()) ?>" dir="<?= \VazinCMS\UiLocale::direction() ?>" data-theme="<?= Security::e($identityTheme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title><?= Security::e(($title ?? 'مدیریت') . ' · ' . $customerBrand) ?></title>
  <script nonce="<?= Security::e(Security::cspNonce()) ?>">try{const t=localStorage.getItem('site-admin-theme');if(['light','dark','system'].includes(t))document.documentElement.dataset.theme=t}catch(e){}</script>
  <link rel="preload" href="/assets/Vazirmatn.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/app-14.css?v=<?=rawurlencode($appVersion)?>">
  <link rel="stylesheet" href="/assets/app-30.css">
  <link rel="stylesheet" href="/assets/auth-fix-521.css?v=5.2.1">
  <link rel="stylesheet" href="/assets/admin-53.css?v=5.3.0">
  <link rel="stylesheet" href="/assets/admin-60.css?v=6.0.0">
  <link rel="stylesheet" href="/assets/theme-70.css?v=<?=rawurlencode($appVersion)?>">
  <link rel="stylesheet" href="/assets/visa-cases-1050.css?v=<?=rawurlencode($appVersion)?>">
  <link rel="stylesheet" href="/assets/identity-profile-1061.css?v=<?=rawurlencode($appVersion)?>">
  <link rel="stylesheet" href="/assets/platform-1010.css?v=<?=rawurlencode($appVersion)?>">
  <?php if($currentUser): ?>
  <link rel="stylesheet" href="/assets/admin-workspace-v1.css?v=<?=rawurlencode($appVersion)?>">
  <?php endif; ?>
</head>
<body class="<?= $currentUser ? 'cms-workspace-body' : '' ?>">
<?php if ($currentUser): ?>
  <a class="cms-skip-link" href="#cms-main">رفتن به محتوای اصلی</a>
  <div class="cms-workspace-frame">
    <?php include $root.'/views/partials/admin-sidebar.php'; ?>
    <div class="cms-workspace-main">
      <header class="cms-admin-topbar">
        <div class="cms-topbar-heading">
          <button type="button" class="cms-nav-toggle" aria-expanded="false" aria-controls="admin-navigation" data-cms-nav-toggle aria-label="باز کردن منوی مدیریت">☰</button>
          <div class="cms-breadcrumb"><span>فضای مدیریت</span><span aria-hidden="true">/</span><strong><?= Security::e((string)($title ?? 'پیشخوان')) ?></strong></div>
        </div>
        <div class="cms-topbar-actions">
          <a class="cms-preview-link" href="/" target="_blank" rel="noopener noreferrer">نمایش سایت ↗</a>
          <button type="button" class="theme-toggle cms-topbar-theme" data-theme-toggle aria-label="تغییر ظاهر">◐ <span>ظاهر</span></button>
          <?php if (\VazinCMS\VazinIdClient::configured()): ?>
          <form class="cms-connect-id" method="post" action="/auth/vazin-id/start">
            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>">
            <input type="hidden" name="return_to" value="/admin">
            <button type="submit">اتصال Vazin ID</button>
          </form>
          <?php endif; ?>
          <span class="cms-topbar-profile">
            <?php if ($identityPicture !== ''): ?><img src="<?= Security::e($identityPicture) ?>" width="34" height="34" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php else: ?><span class="cms-profile-fallback" aria-hidden="true"><?= Security::e(mb_substr($displayName,0,1)) ?></span><?php endif; ?>
            <span class="cms-profile-label"><strong><?= Security::e($displayName) ?></strong><small><?= Security::e((string)($currentUser['role'] ?? '')) ?></small></span>
          </span>
          <form class="cms-topbar-logout" method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrf()) ?>"><button type="submit" aria-label="خروج امن از پنل">خروج</button></form>
        </div>
      </header>
<?php endif; ?>
<main id="cms-main" class="<?= $currentUser ? 'cms-workspace-content' : '' ?>">
