<?php
use VazinCMS\Security;
$h=static fn($v)=>Security::e((string)$v);
$typeLabels=['brand'=>'نام و نشان','nav'=>'فهرست سایت','button'=>'دکمهٔ پیوند','text'=>'متن','contact'=>'اطلاعات تماس','social'=>'شبکه‌های اجتماعی','copyright'=>'حق نشر'];
$partLabels=['header'=>'سربرگ','footer'=>'پابرگ'];
?>
<link rel="stylesheet" href="/assets/template-builder-127.css?v=10.30.3">
<section class="page-head">
  <h1>قالب‌ساز</h1>
  <p>سربرگ و پابرگ را از اجزای امن بسازید، اجزا را در سربرگ و پابرگ دوباره استفاده و برای هر قالب مستقل تنظیم کنید و خروجی عمومی را آزمایش کنید.</p>
</section>
<?php if($message):?><div class="alert success" role="status"><?=$h($message)?></div><?php endif;?>
<?php if($error):?><div class="alert error" role="alert" <?=$conflict?'data-template-conflict':''?>><?=$h($error)?></div><?php endif;?>
<div class="vz-template-builder" data-template-builder>
<form id="template-builder-form" class="card vz-template-builder-form" method="post" <?=$conflict?'data-conflict="1"':''?>>
  <input type="hidden" name="_csrf" value="<?=$h(Security::csrf())?>">
  <input type="hidden" name="template_version" value="<?=$h($version)?>">
  <input type="hidden" name="template_payload" data-template-payload value="">
  <input type="hidden" name="template_key" value="<?=$h($selectedKey)?>">
  <div class="vz-template-toolbar">
    <label>قالب مستقل
      <select id="template-select" aria-label="انتخاب قالب">
        <?php foreach($templates as $key=>$item):?>
        <option value="<?=$h($key)?>" <?=$key===$selectedKey?'selected':''?>><?=$h($item['name'])?><?=$key===$activeKey?' — فعال':''?></option>
        <?php endforeach;?>
      </select>
    </label>
    <span class="vz-template-active-state" <?=$state['enabled']&&$selectedKey===$activeKey?'':'hidden'?>>قالب فعال سایت</span>
  </div>
  <p class="vz-template-notice">قالب فعال، ظاهر عمومی سایت را تغییر می‌دهد. ویرایش و پیش‌نمایش قبل از ذخیره فقط محلی‌اند؛ ذخیرهٔ قالب فعال، ظاهر عمومی را تغییر می‌دهد. ذخیرهٔ قالب غیرفعال آن را فعال نمی‌کند. اجزا به‌صورت دادهٔ محدود ذخیره می‌شوند؛ HTML، JavaScript و CSS خام پذیرفته نمی‌شود.</p>
  <p class="vz-template-notice">برای طراحی یکپارچهٔ هدر، فوتر و بدنه با کتابخانهٔ اجزای مشترک و تاریخچهٔ واحد، از <a href="/admin/layouts?kind=header">طراحی هدر</a>، <a href="/admin/layouts?kind=footer">طراحی فوتر</a> و <a href="/admin/layouts?kind=page">طراحی صفحه</a> استفاده کنید. پس از ذخیرهٔ طراحی یکپارچه، همان خروجی در سایت عمومی جایگزین هدر/فوتر قدیمی می‌شود.</p>
  <div class="vz-template-name-row">
    <label>نام قالب<input type="text" name="template_name" data-template-name maxlength="60" value="<?=$h($template['name'])?>" required></label>
    <label>چیدمان سربرگ
      <select data-layout="header">
        <option value="inline" <?=$template['header_layout']==='inline'?'selected':''?>>ردیفی</option>
        <option value="centered" <?=$template['header_layout']==='centered'?'selected':''?>>وسط‌چین</option>
        <option value="stacked" <?=$template['header_layout']==='stacked'?'selected':''?>>عمودی</option>
      </select>
    </label>
    <label>چیدمان پابرگ
      <select data-layout="footer">
        <option value="columns" <?=$template['footer_layout']==='columns'?'selected':''?>>چندستونه</option>
        <option value="stacked" <?=$template['footer_layout']==='stacked'?'selected':''?>>عمودی</option>
      </select>
    </label>
  </div>
  <section class="vz-template-library" aria-labelledby="component-library-title">
    <div class="vz-template-section-head">
      <div><h2 id="component-library-title">کتابخانهٔ اجزای قابل استفادهٔ مجدد</h2><p>هر جزء را می‌توانید هم‌زمان در سربرگ و پابرگ قرار دهید.</p></div>
      <span data-component-count><?=count($template['components'])?> / 16</span>
    </div>
    <div class="vz-template-add-component">
      <label>نوع جزء
        <select id="new-component-type">
          <?php foreach($typeLabels as $type=>$label):?><option value="<?=$h($type)?>"><?=$h($label)?></option><?php endforeach;?>
        </select>
      </label>
      <label>نام نمایشی<input id="new-component-label" maxlength="50" placeholder="مثلاً تماس با ما"></label>
      <button type="button" data-add-component>افزودن به کتابخانه</button>
    </div>
    <div class="vz-template-component-library" data-component-library>
      <?php foreach($template['components'] as $id=>$component):?>
      <article class="vz-template-component-card" data-component-card data-component-id="<?=$h($id)?>">
        <div class="vz-template-component-card-head">
          <strong data-component-title><?=$h($component['label'])?></strong>
          <code><?=$h($id)?></code>
          <button type="button" class="danger" data-remove-component>حذف جزء</button>
        </div>
        <div class="vz-template-component-fields">
          <label>شناسه<input type="text" data-component-id-value value="<?=$h($id)?>" readonly></label>
          <label>نوع جزء<select data-component-type><?php foreach($typeLabels as $type=>$label):?><option value="<?=$h($type)?>" <?=$component['type']===$type?'selected':''?>><?=$h($label)?></option><?php endforeach;?></select></label>
          <label>نام<input type="text" data-component-label maxlength="50" value="<?=$h($component['label'])?>"></label>
          <div class="vz-template-component-props" data-component-props>
            <?php $props=$component['props']; switch($component['type']):
              case 'brand':?>
                <label>شعار کوتاه<textarea data-prop="tagline" maxlength="120" rows="2"><?=$h($props['tagline']??'')?></textarea></label>
              <?php break;case 'button':?>
                <label>متن دکمه<input data-prop="text" maxlength="60" value="<?=$h($props['text']??'')?>"></label>
                <label>پیوند داخلی<input data-prop="href" dir="ltr" value="<?=$h($props['href']??'/fa')?>"></label>
              <?php break;case 'text':?>
                <label>متن<textarea data-prop="text" maxlength="300" rows="3"><?=$h($props['text']??'')?></textarea></label>
              <?php break;case 'contact':?>
                <label>تلفن<input data-prop="phone" dir="ltr" maxlength="40" value="<?=$h($props['phone']??'')?>"></label>
                <label>ایمیل<input data-prop="email" dir="ltr" type="email" maxlength="120" value="<?=$h($props['email']??'')?>"></label>
                <label>نشانی<input data-prop="address" maxlength="160" value="<?=$h($props['address']??'')?>"></label>
              <?php break;case 'social':?>
                <?php for($i=0;$i<4;$i++):$link=$props['links'][$i]??['label'=>'','url'=>''];?>
                <div class="vz-template-social-fields"><label>عنوان پیوند <?=($i+1)?><input data-social-label maxlength="30" value="<?=$h($link['label'])?>"></label><label>نشانی HTTPS<input data-social-url dir="ltr" value="<?=$h($link['url'])?>"></label></div>
                <?php endfor;?>
              <?php break;default:?>
                <p class="vz-template-no-props">این جزء تنظیمات متنی اضافه ندارد.</p>
            <?php endswitch;?>
          </div>
        </div>
      </article>
      <?php endforeach;?>
    </div>
  </section>
  <?php foreach($partLabels as $part=>$label):?>
  <section class="vz-template-part" aria-labelledby="<?=$h($part)?>-title">
    <div class="vz-template-section-head"><div><h2 id="<?=$h($part)?>-title"><?=$h($label)?></h2><p>اجزا را اضافه کنید و ترتیب نمایش آن‌ها را تغییر دهید.</p></div><span><?=count($template[$part])?> / 8</span></div>
    <div class="vz-template-part-list" data-part-list="<?=$h($part)?>">
      <?php foreach($template[$part] as $id):?>
      <div class="vz-template-part-item" data-part-item data-component-id="<?=$h($id)?>">
        <strong><?=$h($template['components'][$id]['label']??$id)?></strong><code><?=$h($id)?></code>
        <div class="vz-template-part-actions">
          <button type="button" data-move="up" aria-label="انتقال به بالا">↑</button>
          <button type="button" data-move="down" aria-label="انتقال به پایین">↓</button>
          <button type="button" data-remove-from-part>حذف از <?=$h($label)?></button>
        </div>
      </div>
      <?php endforeach;?>
    </div>
    <div class="vz-template-add-to-part">
      <label>انتخاب جزء از کتابخانه
        <select data-component-pick>
          <?php foreach($template['components'] as $id=>$component):?><option value="<?=$h($id)?>"><?=$h($component['label'])?></option><?php endforeach;?>
        </select>
      </label>
      <button type="button" data-add-to-part="<?=$h($part)?>">افزودن به <?=$h($label)?></button>
    </div>
  </section>
  <?php endforeach;?>
  <div class="vz-template-actions">
    <button type="submit" name="action" value="save" <?=$conflict?'disabled':''?>>ذخیرهٔ تغییرات قالب</button>
    <button type="submit" name="action" value="save_activate" <?=$conflict?'disabled':''?>>ذخیره و فعال‌سازی</button>
    <button type="submit" name="action" value="activate" <?=$conflict?'disabled':''?>>فعال‌سازی نسخهٔ ذخیره‌شده</button>
    <button type="submit" name="action" value="reset" <?=$conflict?'disabled':''?>>بازنشانی این قالب</button>
    <button type="submit" name="action" value="restore" <?=$conflict||!$previousAvailable?'disabled':''?>>بازگردانی تغییر قبلی</button>
  </div>
  <?php if(str_starts_with($selectedKey,'custom-')):?>
  <button type="submit" class="danger" name="action" value="delete" <?=$conflict?'disabled':''?> data-delete-template>حذف این قالب سفارشی</button>
  <?php endif;?>
  <div class="vz-template-duplicate">
    <label>نام نسخهٔ جدید<input type="text" name="new_template_name" maxlength="60" placeholder="مثلاً فروشگاهی"></label>
    <button type="submit" name="action" value="duplicate" <?=$conflict?'disabled':''?>>ساخت قالب مستقل از این قالب</button>
  </div>
</form>
<aside class="card vz-template-preview-panel">
  <h2>پیش‌نمایش عمومی قالب</h2>
  <p>پیش‌نمایش محلی است و تا فعال‌سازی، سایت عمومی را تغییر نمی‌دهد.</p>
  <div class="vz-template-preview-controls">
    <button type="button" data-preview="desktop" aria-pressed="true">دسکتاپ</button>
    <button type="button" data-preview="mobile" aria-pressed="false">موبایل</button>
  </div>
  <div class="vz-template-preview-frame" data-template-preview>
    <div class="vz-template-preview-header" data-preview-part="header"></div>
    <main class="vz-template-preview-main"><h3>عنوان نمونهٔ صفحه</h3><p>این پیش‌نمایش چیدمان سربرگ، محتوای صفحه و پابرگ را نشان می‌دهد.</p><div class="vz-template-preview-card">بخش محتوای صفحه</div></main>
    <div class="vz-template-preview-footer" data-preview-part="footer"></div>
  </div>
  <p class="vz-template-preview-status" data-preview-status role="status" aria-live="polite">پیش‌نمایش فقط در همین صفحه تغییر می‌کند.</p>
</aside>
</div>
<script src="/assets/template-builder-127.js?v=10.30.3" defer></script>
