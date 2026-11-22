<?php
use VazinCMS\{Security,SiteDesign};
$h=static fn($v)=>Security::e((string)$v);
$colorLabels=['primary'=>'رنگ اصلی و دکمه','background'=>'پس‌زمینهٔ سایت','surface'=>'پس‌زمینهٔ کارت‌ها','text'=>'رنگ متن'];
$options=['content_width'=>['عنوان'=>'عرض محتوا (پیکسل)','values'=>['720','960','1180','1360']],
 'text_size'=>['عنوان'=>'اندازهٔ متن (پیکسل)','values'=>['14','15','16','17','18','19','20','21','22']],
 'radius'=>['عنوان'=>'گردی گوشه‌ها (پیکسل)','values'=>['0','8','16','24']],
 'spacing'=>['عنوان'=>'فاصلهٔ داخلی (پیکسل)','values'=>['16','24','32']]];
?>
<link rel="stylesheet" href="/assets/site-design-126.css?v=10.30.3">
<section class="page-head"><h1>طراحی سایت</h1><p>رنگ، اندازه و چیدمان قالب فعلی را یکجا تنظیم کنید؛ انتخاب الگو فقط پیش‌نمایش را تغییر می‌دهد.</p></section>
<?php if($message):?><div class="alert success" role="status" data-design-saved><?=$h($message)?></div><?php endif;?>
<?php if($error):?><div class="alert error" role="alert" <?= $conflict?'data-design-conflict':'' ?>><?=$h($error)?></div><?php endif;?>
<div class="vz-design-workspace">
<form id="site-design-form" method="post" class="card" <?= $conflict?'data-conflict="1"':'' ?>>
<input type="hidden" name="_csrf" value="<?=$h(Security::csrf())?>">
<input type="hidden" name="design_version" value="<?=$h($formVersion)?>">
<p class="vz-design-notice">ذخیره، ظاهر عمومی همین سایت را تغییر می‌دهد؛ محتوای صفحه‌ها، وضعیت انتشار و قالب‌های اجرایی دست‌نخورده می‌مانند.</p>
<fieldset><legend>الگوهای آمادهٔ استایل</legend><div class="vz-design-presets">
<?php foreach($presets as $key=>$item):?><button type="button" data-design-preset="<?=$h($key)?>" data-design-values="<?=$h(json_encode($item['values'],JSON_THROW_ON_ERROR))?>"><?=$h($item['label'])?></button><?php endforeach;?>
</div><small>این‌ها الگوهای طراحی همین قالب‌اند، نه قالب‌های مستقل یا ویرایشگر کامل سربرگ.</small></fieldset>
<fieldset><legend>رنگ‌ها</legend><div class="vz-design-controls">
<?php foreach($colorLabels as $key=>$label):?><label><?=$h($label)?><input type="color" name="design[<?=$h($key)?>]" data-design-key="<?=$h($key)?>" value="<?=$h($values[$key])?>"><output data-color-label="<?=$h($key)?>"><?=$h($values[$key])?></output></label><?php endforeach;?>
</div></fieldset>
<fieldset><legend>اندازه و چیدمان</legend><div class="vz-design-controls">
<?php foreach($options as $key=>$item):?><label><?=$h($item['عنوان'])?><select name="design[<?=$h($key)?>]" data-design-key="<?=$h($key)?>"><?php foreach($item['values'] as $option):?><option value="<?=$h($option)?>" <?=$values[$key]===$option?'selected':''?>><?=$h($option)?></option><?php endforeach;?></select></label><?php endforeach;?>
<label>چیدمان سربرگ<select name="design[header]" data-design-key="header"><option value="inline" <?=$values['header']==='inline'?'selected':''?>>ردیفی</option><option value="centered" <?=$values['header']==='centered'?'selected':''?>>وسط‌چین</option></select></label>
</div><p>فونت فارسی سایت «وزیرمتن» حفظ می‌شود. حالت تیره، رنگ‌های خوانای خود را نگه می‌دارد.</p></fieldset>
<p id="design-preview-status" role="status" aria-live="polite">پیش‌نمایش محلی؛ بدون ذخیره در سرور.</p>
<div class="vz-design-actions">
<button type="submit" name="action" value="save" <?= $conflict?'disabled':'' ?>>ذخیرهٔ طرح سایت</button>
<button type="button" data-design-revert>لغو تغییرهای این فرم</button>
<button type="submit" name="action" value="restore" <?=($conflict||!is_array($state['previous']))?'disabled':''?>>بازگردانی طرح قبلی</button>
<button type="submit" name="action" value="reset" <?=$conflict?'disabled':''?>>بازگشت به ظاهر اصلی قالب</button>
</div>
<?php if($conflict):?><a href="/admin/design" data-design-reload>بازکردن طرح جاری؛ فرم فعلی را پیش از خروج مرور کنید</a><?php endif;?>
</form>
<section class="vz-design-preview-panel" aria-label="پیش‌نمایش خصوصی طراحی">
<h2>پیش‌نمایش قالب</h2><div class="vz-design-viewport-buttons"><button type="button" data-preview-size="desktop" aria-pressed="true">دسکتاپ</button><button type="button" data-preview-size="mobile" aria-pressed="false">موبایل</button></div>
<div class="vz-design-preview" id="design-preview" data-vz-design="1" data-vz-design-header="<?=$h($values['header'])?>">
<header class="travel-header"><strong class="travel-brand">نام سایت شما</strong><nav aria-label="نمونهٔ فهرست"><span>خانه</span><span>خدمات</span><span>تماس</span></nav></header>
<div class="travel-main"><article class="public-card"><h2>از ایده تا صفحهٔ آماده</h2><div class="content-body"><p>این متن، نمونهٔ نمایش عنوان و محتوای سایت شماست. با انتخاب رنگ‌ها و اندازه‌ها، خوانایی و فاصله‌ها را همین‌جا بررسی کنید.</p><p>پیش‌نمایش تغییرات را بدون ذخیره یا انتشار نشان می‌دهد.</p><button class="vz-design-sample-button" type="button">دکمهٔ اصلی</button></div></article></div>
<footer class="vz-design-preview-footer">سربرگ، کارت محتوا و فوتر — قالب فعلی</footer>
</div></section></div>
<script src="/assets/site-design-126.js?v=10.30.3" defer></script>
