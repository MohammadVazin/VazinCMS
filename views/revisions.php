<?php
use VazinCMS\Security;
$pageFilter=(int)($pageFilter??0);$error=$error??null;
?>
<link rel="stylesheet" href="/assets/content-revisions-124.css?v=10.30.3-revisions">
<section class="admin-title"><div><span class="eyebrow">تاریخچهٔ محتوا</span><h1>نسخه‌های قبلی<?=$pageFilter>0?' این صفحه':''?></h1><p>پیش از هر ذخیرهٔ دستی یا بازگردانی، نسخهٔ قبلی نگهداری می‌شود. ذخیرهٔ خودکار موقت، جدا از این تاریخچه است.</p></div><div class="title-actions"><span class="metric"><b><?=count($revisions)?></b> نسخهٔ اخیر</span><?php if($pageFilter>0):?><a class="button secondary" data-back-to-editor href="/admin/pages?edit=<?=$pageFilter?>">بازگشت به ویرایشگر</a><a class="button ghost" href="/admin/revisions">همهٔ صفحه‌ها</a><?php endif;?></div></section>
<?php if($error):?><div class="alert error" role="alert"><?=Security::e($error)?></div><?php endif;?>
<?php if(isset($_GET['restored'])):?><div class="notice success" role="status">نسخهٔ انتخابی بازگردانی شد. نسخهٔ قبل از بازگردانی نیز در همین تاریخچه محفوظ است.</div><?php endif;?>
<?php if(isset($_GET['refresh_warning'])):?><div class="notice" role="alert">محتوا بازگردانی شد؛ به‌روزرسانی نمایه، کش یا ثبت رویداد نیاز به بررسی دارد.</div><?php endif;?>
<div class="vz-revision-help"><strong>بازگردانی چه چیزی را تغییر می‌دهد؟</strong><p>عنوان، متن، بلوک‌ها و عنوان و توضیح SEO نسخهٔ انتخابی جایگزین می‌شوند. نشانی، زبان، مالکیت و وضعیت انتشار تغییر نمی‌کنند. اگر صفحه منتشرشده باشد، خروجی عمومی آن نیز پس از بازگردانی تغییر می‌کند.</p></div>
<section class="revision-list vz-revisions" aria-label="نسخه‌های ذخیره‌شده">
<?php foreach($revisions as $revision): $state=(string)($revision['snapshot_state']??'invalid'); ?>
<article class="revision-card vz-revision-card" data-page-id="<?=(int)$revision['page_id']?>" data-revision-id="<?=(int)$revision['id']?>" data-snapshot-state="<?=Security::e($state)?>">
 <div class="vz-revision-description">
  <h2><?=Security::e((string)$revision['title'])?></h2>
  <p class="vz-revision-meta">صفحهٔ فعلی: <?=Security::e((string)$revision['page_title'])?> · <?=Security::e((string)$revision['locale'])?> · <time><?=Security::e((string)$revision['created_at'])?></time> · نسخهٔ <?=(int)$revision['id']?></p>
  <span class="vz-revision-badge"><?php if($state==='complete'):?>متن و ساختار بلوک‌ها محفوظ · <?=(int)$revision['top_level_blocks']?> بلوک اصلی<?php elseif($state==='legacy'):?>نسخهٔ قدیمی: فقط متن ثبت شده است<?php else:?>نسخهٔ نامعتبر؛ بازگردانی غیرفعال<?php endif;?></span>
  <?php if(($revision['revision_kind']??'')==='restore'):?><span class="vz-revision-badge">نسخهٔ محفوظ قبل از بازگردانی</span><?php endif;?>
  <p><?=Security::e(mb_substr(strip_tags((string)$revision['body']),0,150))?></p>
  <details><summary>مشاهدهٔ متن و SEO این نسخه</summary><div class="vz-revision-preview"><h3><?=Security::e((string)$revision['title'])?></h3><pre><?=Security::e(mb_substr((string)$revision['body'],0,12000))?></pre><dl><dt>عنوان SEO</dt><dd><?=Security::e((string)($revision['meta_title']??''))?></dd><dt>توضیح SEO</dt><dd><?=Security::e((string)($revision['meta_description']??''))?></dd></dl></div></details>
  <?php if($state==='legacy'):?><p class="vz-revision-warning">در این نسخهٔ قدیمی ساختار ستون‌ها و تصاویر ذخیره نشده است. بازگردانی، بلوک‌های متنی را از متن قدیمی می‌سازد؛ ساختار ثبت‌نشده قابل بازیابی نیست.</p><?php endif;?>
 </div>
 <div class="vz-revision-actions"><a class="button ghost" href="/admin/pages?edit=<?=(int)$revision['page_id']?>">ویرایش صفحه</a>
 <?php if($state!=='invalid'):?><form method="post" data-confirm="این نسخه جایگزین محتوای فعلی شود؟ اگر صفحه منتشر است، خروجی عمومی هم تغییر می‌کند. نسخهٔ فعلی محفوظ خواهد ماند."><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="revision_id" value="<?=(int)$revision['id']?>"><input type="hidden" name="page_id" value="<?=(int)$revision['page_id']?>"><input type="hidden" name="base_revision" value="<?=Security::e((string)$revision['base_revision'])?>"><button class="secondary" data-restore-revision>بازگردانی این نسخه</button></form><?php endif;?>
 </div>
</article>
<?php endforeach;?>
<?php if(!$revisions):?><div class="empty-state"><b>هنوز نسخه‌ای در این فهرست وجود ندارد</b><p>پس از ذخیرهٔ تغییرات یک صفحه، نسخهٔ قبلی در اینجا ظاهر می‌شود.</p></div><?php endif;?>
</section>
