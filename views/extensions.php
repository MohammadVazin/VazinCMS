<?php use VazinCMS\Security; ?>
<section class="page-head"><h1>قالب‌ها و ماژول‌ها</h1><p>هسته فقط امکانات پایه را نگه می‌دارد؛ قابلیت‌های تخصصی را به‌صورت بستهٔ مستقل نصب و فعال کنید.</p></section>
<?php if($message):?><div class="alert success"><?=Security::e($message)?></div><?php endif;?>
<?php if($error):?><div class="alert error"><?=Security::e($error)?></div><?php endif;?>
<section class="card"><h2>افزودن یا ارتقای بسته</h2><p>بسته باید ZIP معتبر با فایل <code>vazin-extension.json</code> باشد. ارتقا فقط با نسخهٔ بالاتر و پس از غیرفعال‌سازی انجام می‌شود. کد افزونه با سطح دسترسی برنامه اجرا می‌شود؛ فقط بستهٔ مورداعتماد نصب کنید.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="install"><label>فایل بسته<input type="file" name="package" accept=".zip,application/zip" required></label><button>بررسی و نصب</button></form></section>
<section class="grid two">
<?php foreach($extensions as $extension):$manifest=$extension['manifest']??[];$status=(string)$extension['status'];?>
<article class="card"><div class="eyebrow"><?=($extension['extension_type']==='theme'?'قالب':'ماژول')?> · <?=($extension['source']==='bundled'?'همراه هسته':($extension['source']==='runtime'?'نصب‌شده':'قدیمی'))?></div><h2><?=Security::e($extension['name'])?></h2><p><?=Security::e((string)($extension['description']??''))?></p><p><code><?=Security::e($extension['extension_key'])?></code> · نسخه <?=Security::e($extension['version'])?> · <strong><?=Security::e($status)?></strong></p>
<?php if(!empty($extension['compatibility_errors'])):?><div class="alert error"><?php foreach($extension['compatibility_errors'] as $problem):?><div><?=Security::e($problem)?></div><?php endforeach;?></div><?php endif;?>
<div class="actions">
<?php if($status!=='active'&&$status!=='removed'&&empty($extension['compatibility_errors'])):?><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="extension_key" value="<?=Security::e($extension['extension_key'])?>"><button>فعال‌سازی</button></form><?php endif;?>
<?php if($status==='active'&&$extension['extension_type']==='module'):?><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="extension_key" value="<?=Security::e($extension['extension_key'])?>"><button>غیرفعال‌سازی</button></form><?php endif;?>
<?php if($extension['source']==='runtime'&&$status!=='active'&&$status!=='removed'):?><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="extension_key" value="<?=Security::e($extension['extension_key'])?>"><button class="danger">انتقال به بایگانی</button></form><?php endif;?>
</div></article>
<?php endforeach;?>
</section>
