<?php
use VazinCMS\Security;
$title=$page['title']??$siteName;
$host=strtolower(preg_replace('/:\\d+$/','',(string)($_SERVER['HTTP_HOST']??'')));
$slug=(string)($page['slug']??'');
$isTravelDemo=$host==='travel.vazin.online'&&in_array($slug,['travel-services','student-russia','travel-request','visa-countries'],true);
$demoCopy=['fa'=>['نمونهٔ قابل شخصی‌سازی برای آژانس','این صفحه یک نمونهٔ محتوایی از چیزی است که دارندهٔ لایسنس می‌تواند با برند، خدمات، قوانین و متن خودش منتشر کند. Vazin این خدمات را مستقیماً به مسافر نهایی ارائه نمی‌کند.'],'ru'=>['Настраиваемый пример для агентства','Это демонстрационная страница того, что лицензиат может опубликовать под своим брендом, со своими услугами и правилами. Vazin не продаёт эти услуги путешественнику напрямую.'],'en'=>['Customizable agency demo','This is a demo of content a licensee can publish under its own brand, services and policies. Vazin does not sell these travel services directly to the end traveller.'],'ar'=>['ديمو قابل للتخصيص للوكالة','هذه صفحة نموذجية لما يمكن لصاحب الترخيص نشره بعلامته وخدماته وسياساته. لا تبيع Vazin هذه الخدمات مباشرة للمسافر النهائي.']];
$d=$demoCopy[$locale??'en']??$demoCopy['en'];
?>
<main class="public-main"><article class="public-card">
<?php if($isTravelDemo):?><aside class="platform-note" data-white-label-demo><strong><?=Security::e($d[0])?></strong><p><?=Security::e($d[1])?></p><a class="primary" href="/<?=Security::e($locale??'en')?>/agency"><?=Security::e(($locale??'en')==='ru'?'Демо и лицензия':(($locale??'en')==='fa'?'درخواست دمو و شرایط لایسنس':(($locale??'en')==='ar'?'طلب الديمو وشروط الترخيص':'Request demo & licensing')))?> ↗</a></aside><?php endif;?>
<h1><?=Security::e($page['title']??$siteName)?></h1>
<?php if($page):?><?php if(($page['content_type']??'page')==='post'):?><time datetime="<?=Security::e((string)($page['published_at']?:$page['created_at']))?>"><?=Security::e(substr((string)($page['published_at']?:$page['created_at']),0,10))?></time><?php endif;?><div class="content-body"><?=nl2br(Security::e($page['body']))?></div><?php else:?><p>این صفحه هنوز برای دمو منتشر نشده است.</p><?php endif;?></article></main>
