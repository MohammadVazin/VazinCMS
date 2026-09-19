<?php
use VazinCMS\{Security,SiteLocale,ThemeManager};

$rtl=in_array($locale??'ru',['fa','ar'],true);
$lang=$locale??'ru';
$meta=$meta??[];
$copy=SiteLocale::text($lang);
$travelHost=strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??'')));
$isTravelPlatform=(($profile??'')==='travel')||$travelHost==='travel.vazin.online';
$travelProductMeta=[
 'fa'=>['title'=>'Vazin Travel | پلتفرم وایت‌لیبل سفر برای آژانس‌ها','description'=>'Vazin Travel پلتفرم وایت‌لیبل لایسنس‌دار برای آژانس‌هاست: برند و دامنهٔ خود آژانس، پنل تیم، مدل فروش و اتصال‌های قراردادی.'],
 'ru'=>['title'=>'Vazin Travel | White-label платформа для туристических агентств','description'=>'Vazin Travel — лицензируемая white-label платформа: бренд и домен агентства, рабочее место команды, модели продаж и контрактные интеграции.'],
 'en'=>['title'=>'Vazin Travel | White-label travel platform for agencies','description'=>'Vazin Travel is a licensed white-label platform for travel businesses: agency branding, domain, team workspace, sales modes and contract-backed integrations.'],
 'ar'=>['title'=>'Vazin Travel | منصة سفر White-label للوكالات','description'=>'Vazin Travel منصة White-label مرخّصة للوكالات: العلامة والنطاق ومساحة الفريق ونماذج البيع والتكاملات التعاقدية.'],
];
if($isTravelPlatform&&($viewName??'')==='travel-platform-home')$meta=array_replace($meta,$travelProductMeta[$lang]??$travelProductMeta['en'],['canonical'=>'https://travel.vazin.online/'.$lang,'schema_type'=>'WebPage']);
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
$manualMenuCopy=['fa'=>['intake'=>'فرم دستی eVisa','tracking'=>'پیگیری پرونده'],'ru'=>['intake'=>'Ручной eVisa-кейс','tracking'=>'Отслеживание дела'],'en'=>['intake'=>'Manual eVisa case','tracking'=>'Track case'],'ar'=>['intake'=>'ملف eVisa يدوي','tracking'=>'متابعة الملف']];
$demoSlugs=['travel-services','student-russia','travel-request','visa-countries'];
if($isTravelPlatform&&isset($page['slug'])&&in_array((string)$page['slug'],$demoSlugs,true)){
 $robots='noindex,follow';
 $demoPrefix=['fa'=>'نمونهٔ قابل شخصی‌سازی','ru'=>'Демо-шаблон','en'=>'Demo template','ar'=>'قالب تجريبي'][$lang]??'Demo template';
 $demoDescription=['fa'=>'نمونهٔ صفحه‌ای که دارندهٔ لایسنس Vazin Travel می‌تواند با برند، خدمات و قوانین خودش شخصی‌سازی کند.','ru'=>'Пример страницы, которую лицензиат Vazin Travel настраивает под свой бренд, услуги и правила.','en'=>'An example page a Vazin Travel licensee can customize with its own brand, services and policies.','ar'=>'صفحة نموذجية يمكن لمرخّص Vazin Travel تخصيصها بعلامته وخدماته وسياساته.'][$lang]??'Demo template for a Vazin Travel licensee.';
 $meta['title']=$demoPrefix.' — '.(string)($page['title']??'Vazin Travel').' | Vazin Travel';
 $description=$demoDescription;$ogTitle=$meta['title'];$ogDescription=$demoDescription;
}
$publicMenu=[];
if($isTravelPlatform){
    $platformMenu=[
      'fa'=>[['محصول','/'.$lang],['امکانات','/'.$lang.'#platform-features'],['مدل فروش','/'.$lang.'#sales-modes'],['دموی سایت آژانس','/'.$lang.'/page/travel-services'],['دمو و لایسنس','/'.$lang.'/agency']],
      'ru'=>[['Продукт','/'.$lang],['Возможности','/'.$lang.'#platform-features'],['Модели продаж','/'.$lang.'#sales-modes'],['Демо сайта агентства','/'.$lang.'/page/travel-services'],['Демо и лицензия','/'.$lang.'/agency']],
      'en'=>[['Product','/'.$lang],['Features','/'.$lang.'#platform-features'],['Sales modes','/'.$lang.'#sales-modes'],['Agency site demo','/'.$lang.'/page/travel-services'],['Demo & licensing','/'.$lang.'/agency']],
      'ar'=>[['المنتج','/'.$lang],['الميزات','/'.$lang.'#platform-features'],['نماذج البيع','/'.$lang.'#sales-modes'],['ديمو موقع الوكالة','/'.$lang.'/page/travel-services'],['الديمو والترخيص','/'.$lang.'/agency']],
    ];
    foreach(($platformMenu[$lang]??$platformMenu['en']) as [$label,$url])$publicMenu[]=['label'=>$label,'url'=>$url];
}else{
    foreach((array)($menu??[])as$item){
        $url=(string)($item['url']??'');$path=rtrim($url,'/')?:'/';
        if(($profile??'')==='visa'){
            $casePaths=['/'.$lang.'/visa','/'.$lang.'/visa/intake','/'.$lang.'/request','/'.$lang.'/visa-selector'];
            if(in_array($path,$casePaths,true))$item['label']=$manualMenuCopy[$lang]['intake']??$manualMenuCopy['en']['intake'];
            elseif($path==='/'.$lang.'/account')$item['label']=$manualMenuCopy[$lang]['tracking']??$manualMenuCopy['en']['tracking'];
        }
        $publicMenu[]=$item;
    }
}
$themeAsset='';
try{$themeAsset=ThemeManager::assetUrl('theme.css');}catch(\Throwable){}
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
<link rel="stylesheet" href="/assets/visa-checkout-1041.css?v=<?=rawurlencode($appVersion)?>">
<link rel="stylesheet" href="/assets/visa-selector-1042.css?v=<?=rawurlencode($appVersion)?>">
<link rel="stylesheet" href="/assets/visa-cases-1050.css?v=<?=rawurlencode($appVersion)?>">
<link rel="stylesheet" href="/assets/theme-90.css?v=<?=rawurlencode($appVersion)?>">
<link rel="stylesheet" href="/assets/platform-1010.css?v=<?=rawurlencode($appVersion)?>">
<?php if($themeAsset!==''):?><link rel="stylesheet" href="<?=Security::e($themeAsset)?>?v=1.1.1"><?php endif;?>
<?php if($customColor!==''):?><style>:root{--vz-primary:<?=Security::e($customColor)?>}</style><?php endif;?>
</head>
<body class="travel-site profile-<?=Security::e($profile??'default')?>">
<header class="travel-header">
  <a class="travel-brand" href="/<?=$lang?>"><b><?=Security::e($siteName)?></b></a>
  <button class="mobile-menu" type="button" data-menu-toggle aria-label="<?=Security::e($copy['menu'])?>">☰</button>
  <?php if($publicMenu):?><nav data-site-menu><?php foreach($publicMenu as $item):?><a href="<?=Security::e($item['url'])?>"><?=Security::e($item['label'])?></a><?php endforeach;?></nav><?php endif;?>
  <div class="header-tools">
    <label class="language"><span><?=Security::e($copy['language'])?></span><select aria-label="<?=Security::e($copy['language'])?>" data-locale-switch><?php foreach($enabled as $code):?><option value="<?=$code?>" <?=$code===$lang?'selected':''?>><?=Security::e(SiteLocale::NAMES[$code])?></option><?php endforeach;?></select></label>
    <button class="theme-toggle" type="button" data-theme-toggle aria-label="<?=Security::e($copy['theme'])?>">◐</button>
  </div>
</header>
<main class="travel-main">
