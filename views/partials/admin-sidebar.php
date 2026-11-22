<?php
declare(strict_types=1);
use VazinCMS\Security;

$workspacePath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH) ?: '/admin');
$workspaceType = in_array((string)($_GET['type']??'all'),['page','post'],true)?(string)$_GET['type']:'all';
$workspaceAdmin = in_array((string)($currentUser['role'] ?? ''), ['owner','admin'], true);
$workspaceMenu = $workspaceAdmin ? [
    'فضای کار' => [
        ['/admin', 'پیشخوان', '◫'],
    ],
    'محتوا' => [
        ['/admin/pages?type=all', 'همه محتوا', '☰'],
        ['/admin/pages?type=page', 'صفحه‌ها', '▤'],
        ['/admin/pages?type=post', 'نوشته‌ها', '▤'],
        ['/admin/content-types', 'انواع محتوا و فیلدها', '⊞'],
        ['/admin/media', 'کتابخانه رسانه', '▧'],
        ['/admin/menus', 'فهرست‌ها', '☷'],
        ['/admin/revisions', 'تاریخچه و نسخه‌ها', '◷'],
    ],
    'مدیریت' => [
        ['/admin/extensions', 'قالب‌ها و ماژول‌ها', '◇'],
        ['/admin/blueprints', 'کیت‌های آماده سایت', '✦'],
        ['/admin/design', 'طراحی سایت', '◧'],
        ['/admin/template-builder', 'قالب‌ساز', '▦'],
        ['/admin/layouts', 'طراحی صفحه و آرشیو', '▤'],
        ['/admin/settings', 'تنظیمات', '⚙'],
    ],
] : ((string)($currentUser['role'] ?? '') === 'support' ? [
    'فضای کار' => [['/admin', 'پیشخوان پشتیبانی', '◫']],
] : []);
?>
<aside class="cms-sidebar" id="admin-navigation" aria-label="ناوبری مدیریت">
  <div class="cms-sidebar-top">
    <a href="/admin" class="cms-sidebar-brand" aria-label="پیشخوان VazinCMS">
      <span class="cms-brand-mark" aria-hidden="true">V</span>
      <span class="cms-brand-wordmark"><strong>Vazin<span>CMS</span></strong><small>CONTENT MANAGEMENT</small></span>
    </a>
    <button type="button" class="cms-sidebar-close" data-cms-nav-close aria-label="بستن منوی مدیریت">×</button>
  </div>
  <div class="cms-site-selector" role="group" aria-label="سایت جاری">
    <span class="cms-site-avatar" aria-hidden="true"><?= Security::e(mb_substr((string)$customerBrand,0,1)) ?></span>
    <span class="cms-site-selector-name"><small>سایت جاری</small><strong><?= Security::e((string)$customerBrand) ?></strong></span>
    <span class="cms-site-current-label" aria-label="مدیریت چندسایتی در انتظار تأیید">فعال</span>
  </div>
  <div class="cms-sidebar-scroll">
    <label class="cms-menu-search">
      <span class="cms-menu-search-icon" aria-hidden="true">⌕</span>
      <span class="sr-only">جست‌وجو در منوهای مدیریت</span>
      <input type="search" placeholder="جست‌وجو در منوها…" data-cms-menu-search autocomplete="off">
      <kbd>/</kbd>
    </label>
    <nav class="cms-sidebar-nav" aria-label="بخش‌های مدیریت">
      <?php foreach ($workspaceMenu as $groupTitle => $items): ?>
      <section class="cms-nav-group" aria-label="<?= Security::e($groupTitle) ?>">
        <h2><?= Security::e($groupTitle) ?></h2>
        <?php foreach ($items as [$url, $label, $glyph]):
          $target = (string)parse_url($url, PHP_URL_PATH);
          $isActive = $workspacePath === $target
            && ($target !== '/admin/pages' || (str_contains($url,'type='.$workspaceType)));
        ?>
          <a class="cms-nav-link <?= $isActive ? 'is-current' : '' ?>" href="<?= Security::e($url) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="cms-nav-glyph" aria-hidden="true"><?= Security::e($glyph) ?></span><span><?= Security::e($label) ?></span>
            <?php if ($isActive): ?><span class="cms-current-marker" aria-hidden="true"></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </section>
      <?php endforeach; ?>
      <?php if ($workspaceAdmin && is_array($moduleNavigation) && $moduleNavigation): ?>
      <section class="cms-nav-group" aria-label="ابزارهای افزونه">
        <h2>ابزارهای افزونه</h2>
        <?php foreach ($moduleNavigation as $moduleItem):
          $modulePath = (string)($moduleItem['path'] ?? '');
          if (!preg_match('#^/admin/[a-z0-9/_?=&-]+$#D', $modulePath) || str_contains($modulePath, '..')) continue;
        ?>
        <a class="cms-nav-link <?= $workspacePath === parse_url($modulePath,PHP_URL_PATH) ? 'is-current' : '' ?>" href="<?= Security::e($modulePath) ?>">
          <span class="cms-nav-glyph" aria-hidden="true">◇</span><span><?= Security::e(\VazinCMS\UiLocale::t((string)($moduleItem['label'] ?? 'افزونه'))) ?></span>
        </a>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>
    </nav>
    <p class="cms-no-menu-results" data-cms-menu-empty hidden>موردی پیدا نشد.</p>
  </div>
  <div class="cms-sidebar-footer">
    <div class="cms-sidebar-release"><span class="cms-release-dot" aria-hidden="true"></span><span>نسخه آزمایشی رابط مدیریت</span><small><?= Security::e((string)$appVersion) ?></small></div>
    <a class="cms-external-link" href="/" target="_blank" rel="noopener noreferrer">مشاهده سایت <span aria-hidden="true">↗</span></a>
  </div>
</aside>
<button class="cms-sidebar-scrim" type="button" data-cms-nav-scrim aria-label="بستن منوی مدیریت" hidden></button>
