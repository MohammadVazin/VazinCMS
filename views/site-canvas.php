<?php
use VazinCMS\Security;
$e=static fn($v)=>Security::e((string)$v);
$kinds=['page'=>'بدنهٔ صفحه','archive'=>'آرشیو نوشته‌ها','header'=>'هدر سایت','footer'=>'فوتر سایت','component'=>'جزء همگام'];
$pageId=(int)($pageId??0);
$pages=is_array($pages??null)?$pages:[];
$pageInfo=is_array($pageInfo??null)?$pageInfo:null;
$usingPageOverride=(bool)($usingPageOverride??false);
$queryBase='template='.rawurlencode($key);
$pageQuery=$pageId>0?'&page_id='.$pageId:'';
?>
<link rel="stylesheet" href="/assets/site-canvas-128.css?v=10.30.3-page-layout">
<section class="page-head"><h1>طراحی یکپارچهٔ سایت</h1><p>چیدمان اختصاصی صفحه، آرشیو، هدر و فوتر؛ یک کتابخانهٔ مشترک و یک تاریخچه برای تمام بخش‌ها.</p></section>
<?php if($message):?><p role="status" class="alert success"><?=$e($message)?></p><?php endif;?>
<?php if($error):?><p role="alert" class="alert error"><?=$e($error)?></p><?php endif;?>
<nav class="canvas-nav" aria-label="انتخاب طراحی">
<?php foreach($templates as $tk=>$t):?>
  <span><strong><?=$e($t['name'])?></strong>:
  <a href="/admin/layouts?template=<?=$e($tk)?>&kind=page">صفحه</a> ·
  <a href="/admin/layouts?template=<?=$e($tk)?>&kind=archive">آرشیو</a> ·
  <a href="/admin/layouts?template=<?=$e($tk)?>&kind=header">هدر</a> ·
  <a href="/admin/layouts?template=<?=$e($tk)?>&kind=footer">فوتر</a></span>
<?php endforeach;?>
</nav>
<?php if($kind==='page'):?>
<div class="card canvas-page-picker">
  <label>صفحهٔ موردنظر برای طراحی اختصاصی
    <select id="canvas-page-select" aria-label="انتخاب صفحه برای چیدمان اختصاصی">
      <option value="0" <?=$pageId===0?'selected':''?>>چیدمان پیش‌فرض قالب</option>
      <?php foreach($pages as $item):?>
      <option value="<?=$e($item['id'])?>" <?=$pageId===(int)$item['id']?'selected':''?>><?=$e($item['title'])?> — <?=$e($item['locale'])?> — #<?=$e($item['id'])?></option>
      <?php endforeach;?>
    </select>
  </label>
  <?php if($pageInfo):?>
  <p>صفحهٔ انتخاب‌شده: <strong><?=$e($pageInfo['title'])?></strong> · <code><?=$e($pageInfo['slug'])?></code> ·
    <?php if($usingPageOverride):?><span class="canvas-source-badge">چیدمان اختصاصی این صفحه</span><?php else:?><span>از چیدمان پیش‌فرض قالب استفاده می‌کند.</span><?php endif;?>
  </p>
  <?php else:?><p>ویرایش چیدمان پیش‌فرض قالب؛ صفحه‌هایی که چیدمان اختصاصی ندارند از این طرح استفاده می‌کنند.</p><?php endif;?>
</div>
<?php endif;?>
<p class="canvas-notice">کتابخانهٔ اجزای همگام و تاریخچه بین بدنهٔ صفحه، آرشیو، هدر و فوتر مشترک‌اند. هر تغییر در یک محل، تمام ارجاع‌های همان سایت را به‌روز می‌کند. بازگردانی تاریخچه نیز همهٔ این نواحی را با هم برمی‌گرداند. محتوای اصلی نوشته و فایل‌های رسانه حذف نمی‌شوند.</p>
<div class="canvas-workspace">
<form id="site-canvas-form" method="post" class="card" <?=$conflict?'data-conflict="1"':''?>>
<input type="hidden" name="_csrf" value="<?=$e(Security::csrf())?>">
<input type="hidden" name="canvas_version" value="<?=$e($base)?>">
<input type="hidden" name="canvas_target" value="<?=$e($canvasTarget)?>">
<input type="hidden" name="nodes" data-tree-input value="<?=$e(json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?>">
<h2><?=$e($kinds[$kind])?> — <?=$e($templates[$key]['name'])?><?=$pageInfo?' — '.$e($pageInfo['title']):''?></h2>
<?php if($kind==='component'):?><label>نام جزء همگام<input name="label" maxlength="80" value="<?=$e($canvasLabel)?>" required></label><p>این جزء در هدر، فوتر، بدنه و آرشیو همین سایت قابل استفاده است.</p><?php endif;?>
<div class="canvas-toolbar">
<label>نوع بخش<select id="canvas-new-type"><option value="section">سکشن</option><option value="heading">عنوان</option><option value="text">متن</option><option value="button">دکمهٔ داخلی</option><?php if($kind!=='component'):?><option value="shared">ارجاع به جزء همگام</option><?php endif;?></select></label>
<?php if($kind!=='component'):?><label id="canvas-shared-picker-wrap" hidden>انتخاب جزء از کتابخانهٔ مشترک<select id="canvas-shared-picker"><?php foreach($shared as $sid=>$item):?><option value="<?=$e($sid)?>"><?=$e($item['label'])?></option><?php endforeach;?></select></label><?php endif;?>
<button type="button" id="canvas-add-root">افزودن در ریشه</button>
</div>
<p>برای جابه‌جایی می‌توانید کارت را با ماوس بکشید یا از کنترل‌های صفحه‌کلید «بالا/پایین»، «تو‌رفتگی/بیرون‌آوردن» و «انتقال به مقصد» استفاده کنید. حداکثر عمق ۵ و تعداد گره‌ها ۸۰ است.</p>
<div id="canvas-tree" data-shared="<?=$e(json_encode(array_map(static fn($c)=>$c['label'],$shared),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?>"></div>
<p id="canvas-announcement" role="status" aria-live="polite"></p>
<div class="canvas-actions">
<button type="submit" name="action" value="save" <?=$conflict?'disabled':''?>><?=$pageId>0?'ذخیرهٔ چیدمان اختصاصی این صفحه':'ذخیرهٔ طراحی'?></button>
<?php if($kind!=='component'):?><button type="submit" name="action" value="reset" <?=$conflict?'disabled':''?>><?=$pageId>0?'حذف چیدمان اختصاصی و استفاده از قالب':'بازگشت به چیدمان پیش‌فرض'?></button><?php endif;?>
</div>
</form>
<aside class="card"><h2>پیش‌نمایش ساختار</h2><p>پیش‌نمایش محلی؛ خروجی نهایی از مسیر عمومی همان سایت بررسی می‌شود.</p><div id="canvas-preview" class="vz-canvas-public" aria-label="پیش‌نمایش محلی"></div>
<h2>کتابخانهٔ یکپارچهٔ اجزای همگام</h2><p>هر جزء می‌تواند هم‌زمان در هدر، فوتر، بدنه و آرشیو استفاده شود.</p>
<div class="canvas-shared-library"><?php foreach($shared as $sid=>$item):?><a data-edit-shared="<?=$e($sid)?>" href="/admin/layouts?<?=$queryBase?>&kind=component&component=<?=$e($sid)?>"><?=$e($item['label'])?></a><?php endforeach;?></div>
<a id="canvas-new-shared" href="/admin/layouts?<?=$queryBase?>&kind=component&component=s-<?=bin2hex(random_bytes(4))?>">ساخت جزء همگام جدید</a>
</aside></div>
<section class="card canvas-history"><h2>تاریخچهٔ یکپارچهٔ طراحی — ۲۰ نسخهٔ قبلی</h2><p>هر نسخه شامل چیدمان صفحه‌ها، آرشیو، هدر، فوتر، چیدمان‌های اختصاصی صفحه و تمام اجزای همگام است. بازگردانی، وضعیت فعلی را نیز پیش از تغییر محفوظ می‌کند.</p>
<?php if(!$history):?><p>هنوز نسخه‌ای ثبت نشده است.</p><?php endif;?>
<?php foreach($history as $h):?><form method="post" data-history-id="<?=$e($h['id'])?>"><input type="hidden" name="_csrf" value="<?=$e(Security::csrf())?>"><input type="hidden" name="canvas_version" value="<?=$e($base)?>"><input type="hidden" name="canvas_target" value="<?=$e($canvasTarget)?>"><input type="hidden" name="history_id" value="<?=$e($h['id'])?>"><time><?=$e($h['at'])?></time> <code><?=$e($h['action'])?></code><button name="action" value="restore" <?=$conflict?'disabled':''?>>بازگردانی این نسخهٔ کامل</button></form><?php endforeach;?>
</section>
<script src="/assets/site-canvas-128.js?v=10.30.3-page-layout" defer></script>
