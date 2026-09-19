<?php
$footerCopy=\VazinCMS\SiteLocale::text($locale??'ru');
$travelHost=strtolower(preg_replace('/:\\d+$/','',(string)($_SERVER['HTTP_HOST']??'')));
$isTravelPlatform=(($profile??'')==='travel')||$travelHost==='travel.vazin.online';
$platformFooter=['fa'=>'پلتفرم وایت‌لیبل سفر برای آژانس‌ها و کسب‌وکارهای دارای مجوز','ru'=>'White-label платформа для туристических агентств и лицензированного бизнеса','en'=>'White-label travel platform for licensed agencies and travel businesses','ar'=>'منصة سفر White-label للوكالات والأعمال المرخّصة'];
$footerText=$isTravelPlatform?($platformFooter[$locale??'en']??$platformFooter['en']):($settings['footer_text.'.($locale??'ru')]??$settings['footer_text']??$footerCopy['footer']);
?>
</main><footer class="travel-footer"><div><b><?=VazinCMS\Security::e($siteName)?></b><span><?=VazinCMS\Security::e($footerText)?></span></div><div class="footer-links"><?php foreach(['social_telegram'=>'Telegram','social_instagram'=>'Instagram','social_youtube'=>'YouTube'] as $key=>$label):?><?php if(!empty($settings[$key])):?><a href="<?=VazinCMS\Security::e($settings[$key])?>" rel="noopener noreferrer"><?=$label?></a><?php endif;?><?php endforeach;?></div><small>© <?=date('Y')?> <?=VazinCMS\Security::e($siteName)?></small></footer><script src="/assets/security-1061.js?v=<?=rawurlencode($appVersion)?>" defer></script><script src="/assets/theme-90.js?v=<?=rawurlencode($appVersion)?>" defer></script></body></html>
