<?php
use VazinCMS\{Security,SiteLocale,ThemeManager};

$rtl=in_array($locale??'fa',['fa','ar'],true);
$lang=$locale??'fa';
$meta=$meta??[];
$copy=SiteLocale::text($lang);
$customColor=preg_match('/^#[0-9a-fA-F]{6}$/',(string)($settings['primary_color']??''))
    ?(string)$settings['primary_color']:'';
$enabled=json_decode((string)($settings['enabled_locales']??'[]'),true);
if(!is_array($enabled)||!$enabled)$enabled=['fa','ru','en','ar'];
$enabled=array_values(array_intersect(['fa','ru','en','ar'],$enabled));
if(!$enabled)$enabled=['fa','ru','en','ar'];
$description=(string)($meta['description']??$settings['site_description.'.$lang]??$settings['site_description']??$copy['text']);
$robots=(string)($meta['robots']??'index,follow,max-image-preview:large');
$ogTitle=(string)($meta['og_title']??$meta['title']??$siteName);
$ogDescription=(string)($meta['og_description']??$description);
$canonical=(string)($meta['canonical']??'');
$ogType=(string)($meta['type']??'website');
$schemaTypeCandidate=(string)($meta['schema_type']??'Article');
$schemaType=in_array($schemaTypeCandidate,['Article','NewsArticle','BlogPosting','WebPage'],true)
    ?$schemaTypeCandidate:'Article';
$schema=['@context'=>'https://schema.org','@type'=>$schemaType,'headline'=>$ogTitle,'description'=>$ogDescription];
if($canonical!=='')$schema['url']=$canonical;
if(!empty($meta['image']))$schema['image']=(string)$meta['image'];
if(!empty($meta['published_at']))$schema['datePublished']=(string)$meta['published_at'];
if(!empty($meta['updated_at']))$schema['dateModified']=(string)$meta['updated_at'];
$publicMenu=is_array($menu??null)?$menu:[];
?>
<!doctype html>
<html lang="<?=Security::e($lang)?>" dir="<?=$rtl?'rtl':'ltr'?>" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=Security::e(($meta['title']??'')!==''?$meta['title']:($title??$siteName))?></title>
<meta name="description" content="<?=Security::e($description)?>">
<meta name="robots" content="<?=Security::e($robots)?>">
<meta property="og:title" content="<?=Security::e($ogTitle)?>">
<meta property="og:description" content="<?=Security::e($ogDescription)?>">
<meta property="og:type" content="<?=Security::e($ogType)?>">
<?php if(!empty($meta['image'])):?><meta property="og:image" content="<?=Security::e((string)$meta['image'])?>"><?php endif;?>
<?php if($canonical!==''):?><link rel="canonical" href="<?=Security::e($canonical)?>"><meta property="og:url" content="<?=Security::e($canonical)?>"><?php endif;?>
<?php if(!empty($meta['published_at'])):?><meta property="article:published_time" content="<?=Security::e((string)$meta['published_at'])?>"><?php endif;?>
<meta name="theme-color" content="<?=Security::e($customColor?:'#3156d3')?>">
<script type="application/ld+json" nonce="<?=Security::e(Security::cspNonce())?>"><?=json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script nonce="<?=Security::e(Security::cspNonce())?>">try{document.documentElement.dataset.theme=localStorage.getItem('site-theme')||'system'}catch(e){}</script>
<link rel="preload" href="/assets/Vazirmatn.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/travel-40.css?v=<?=rawurlencode($appVersion)?>">
<link rel="stylesheet" href="/assets/theme-90.css?v=<?=rawurlencode($appVersion)?>">
<link rel="stylesheet" href="/assets/platform-1010.css?v=<?=rawurlencode($appVersion)?>">
<link rel="stylesheet" href="/assets/content-columns-121.css?v=10.30.3-gallery-guard">
<link rel="stylesheet" href="<?=Security::e(ThemeManager::assetUrl('theme.css'))?>?v=1.2.0">
<?php if($customColor!==''):?><style>:root{--vz-primary:<?=Security::e($customColor)?>}</style><?php endif;?>
<link rel="stylesheet" href="/assets/site-design-126.css?v=10.30.3">
<link rel="stylesheet" href="/assets/template-builder-127.css?v=10.30.3">
<link rel="stylesheet" href="/assets/site-canvas-128.css?v=10.30.3">
<?php if(is_array($siteDesign??null)):?><style nonce="<?=Security::e(Security::cspNonce())?>">body[data-vz-design="1"]{<?=\VazinCMS\SiteDesign::variables($siteDesign['values'])?>}</style><?php endif;?>
</head>
<body class="travel-site vz-editorial-theme profile-<?=Security::e($profile??'default')?>" <?php if(is_array($siteDesign??null)):?>data-vz-design="1" data-vz-design-header="<?=Security::e($siteDesign['values']['header'])?>"<?php endif;?>>
<?php if(is_string($canvasHeaderHtml??null)):?>
<?=$canvasHeaderHtml?>
<?php else:?>
<?php $customTemplateHeader=\VazinCMS\SiteTemplateBuilder::renderHeader($siteTemplate??null,$siteName,$publicMenu,$settings,$lang); if($customTemplateHeader!==null):?>
<?=$customTemplateHeader?>
<?php else:?>
<header class="travel-header">
  <a class="travel-brand" href="/<?=$lang?>"><b><?=Security::e($siteName)?></b></a>
  <button class="mobile-menu" type="button" data-menu-toggle aria-label="<?=Security::e($copy['menu'])?>">☰</button>
  <?php if($publicMenu):?><nav data-site-menu><?php foreach($publicMenu as $item):?><a href="<?=Security::e($item['url'])?>"><?=Security::e($item['label'])?></a><?php endforeach;?></nav><?php endif;?>
  <div class="header-tools">
    <label class="language"><span><?=Security::e($copy['language'])?></span><select aria-label="<?=Security::e($copy['language'])?>" data-locale-switch><?php foreach($enabled as $code):?><option value="<?=$code?>" <?=$code===$lang?'selected':''?>><?=Security::e(SiteLocale::NAMES[$code])?></option><?php endforeach;?></select></label>
    <button class="theme-toggle" type="button" data-theme-toggle aria-label="<?=Security::e($copy['theme'])?>">◐</button>
  </div>
</header>
<?php endif;?>
<?php endif;?>
<main class="travel-main">
