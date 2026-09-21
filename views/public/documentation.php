<?php
declare(strict_types=1);

use VazinCMS\Security;

$e = static fn(string $value): string => Security::e($value);

$markdown = static function(string $value) use ($e): string {
    /*
     * Documentation is rendered as escaped source text for now.
     * This deliberately avoids executing HTML embedded in repository docs.
     */
    return '<pre class="docs-source">' . $e($value) . '</pre>';
};

$nav = [
    '/docs' => 'Documentation',
    '/download' => 'Download',
    '/developers' => 'Developers',
    '/extensions' => 'Extensions',
    '/themes' => 'Themes',
    '/changelog' => 'Changelog',
];
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">

<title><?=$e($title)?> · VazinCMS</title>

<meta
    name="description"
    content="Official VazinCMS documentation, installation, developer API, extensions, themes and release information."
>

<link
    rel="canonical"
    href="https://cms.vazin.online/<?=$e($section)?>"
>

<style>
:root{
    font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    color-scheme:light dark
}
body{margin:0;background:#0b0d12;color:#f4f6fb}
a{color:inherit}
.docs-shell{max-width:1180px;margin:auto;padding:24px}
.docs-header{display:flex;justify-content:space-between;gap:24px;align-items:center;padding:12px 0 32px}
.docs-brand{font-size:22px;font-weight:800;text-decoration:none}
.docs-nav{display:flex;gap:8px;flex-wrap:wrap}
.docs-nav a{padding:9px 12px;border:1px solid #303644;border-radius:10px;text-decoration:none}
.docs-hero{padding:48px 0 32px}
.docs-hero h1{font-size:clamp(34px,6vw,64px);margin:0 0 14px}
.docs-hero p{max-width:760px;font-size:18px;color:#b8bfcc;line-height:1.7}
.docs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:24px 0 40px}
.docs-card{padding:22px;border:1px solid #303644;border-radius:16px;background:#121620;text-decoration:none}
.docs-card strong{display:block;font-size:18px;margin-bottom:8px}
.docs-card span{color:#aab2c1;line-height:1.6}
.docs-content{padding:28px;border:1px solid #303644;border-radius:16px;background:#121620;margin-bottom:40px}
.docs-source{white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.7 ui-monospace,SFMono-Regular,Consolas,monospace}
code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.docs-footer{border-top:1px solid #303644;padding:24px 0;color:#929bab}
@media(max-width:720px){.docs-header{display:block}.docs-nav{margin-top:20px}}
</style>
</head>

<body>
<div class="docs-shell">

<header class="docs-header">
<a class="docs-brand" href="/">VazinCMS</a>

<nav class="docs-nav" aria-label="Documentation">
<?php foreach ($nav as $href => $label): ?>
<a href="<?=$e($href)?>"><?=$e($label)?></a>
<?php endforeach; ?>
</nav>
</header>

<section class="docs-hero">
<p>VazinCMS <?=$e($version)?></p>
<h1><?=$e($title)?></h1>
<p>
Open-source modular CMS for multilingual and branded websites.
Official documentation is served directly from the release tree so
documentation and application versions stay aligned.
</p>
</section>

<?php if ($section === 'docs'): ?>

<div class="docs-grid">
<a class="docs-card" href="/download">
<strong>Install & Download</strong>
<span>Requirements, installation and upgrade workflow.</span>
</a>

<a class="docs-card" href="/developers">
<strong>Developer API</strong>
<span>OpenAPI 3.1 and authenticated developer endpoints.</span>
</a>

<a class="docs-card" href="/extensions">
<strong>Extensions</strong>
<span>Modules, hooks, routes, signed Marketplace packages and lifecycle.</span>
</a>

<a class="docs-card" href="/themes">
<strong>Themes</strong>
<span>Theme manifests, assets and activation lifecycle.</span>
</a>
</div>

<section class="docs-content">
<h2>Installation</h2>
<?=$markdown($install)?>
</section>

<?php elseif ($section === 'download'): ?>

<section class="docs-content">
<h2>Current release</h2>
<p><strong>VazinCMS <?=$e($version)?></strong></p>
<p>
Use only an official release package. Never publish
<code>.env</code>, database dumps, credentials or signing keys.
</p>
<?=$markdown($install)?>
</section>

<?php elseif ($section === 'developers'): ?>

<section class="docs-content">
<h2>Developer API v3</h2>
<p>
The machine-readable OpenAPI 3.1 specification is available at
<a href="/api/openapi.json"><code>/api/openapi.json</code></a>.
</p>

<p>Current API endpoints include content, forms, learning courses and connectors.</p>

<h3>Upgrade and rollback</h3>
<?=$markdown($upgrade)?>
</section>

<?php elseif ($section === 'extensions' || $section === 'themes'): ?>

<section class="docs-content">
<h2><?= $section === 'themes' ? 'Theme development' : 'Extension development' ?></h2>
<?=$markdown($extensions)?>
</section>

<?php elseif ($section === 'changelog'): ?>

<section class="docs-content">
<?=$markdown($changelog)?>
</section>

<?php endif; ?>

<footer class="docs-footer">
VazinCMS <?=$e($version)?> · AGPL-3.0-or-later · Vazin Online
</footer>

</div>
</body>
</html>
