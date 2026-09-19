<?php
use VazinCMS\Security;

$title = 'انتشار محتوا';
$externalDeliveryEnabled = (bool)($externalDeliveryEnabled ?? false);
$telegramNetworkEnabled = (bool)($telegramNetworkEnabled ?? false);
?>
<section class="panel">
    <h1>انتشار محتوا</h1>
    <p>مقاله منتشرشده را برای شبکه‌های متصل زمان‌بندی کنید. هزینه API هر شبکه متعلق به حساب همان مشتری است.</p>
    <?php if (!$externalDeliveryEnabled): ?>
    <div class="alert error">
        ارسال بیرونی خاموش است و امکان ساخت برنامهٔ انتشار وجود ندارد. برای فعال‌سازی آگاهانه
        <code dir="ltr">EXTERNAL_DELIVERY_ENABLED=true</code> را تنظیم کنید.
    </div>
    <?php elseif (!$telegramNetworkEnabled): ?>
    <div class="alert">ارسال Telegram خاموش است؛ مقصدهای Telegram تا فعال‌شدن <code dir="ltr">TELEGRAM_NETWORK_ENABLED=true</code> قابل انتخاب نیستند.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?=Security::e($error)?></div><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <fieldset <?=$externalDeliveryEnabled ? '' : 'disabled'?>>
            <label>مقاله<select name="page_id" required><?php foreach ($pages as $page): ?><option value="<?=(int)$page['id']?>"><?=Security::e($page['title'].' — '.$page['locale'])?></option><?php endforeach; ?></select></label>
            <label>زمان انتشار<input type="datetime-local" name="scheduled_at" required value="<?=date('Y-m-d\TH:i', time()+300)?>"></label>
            <fieldset>
                <legend>مقصدها</legend>
                <?php foreach ($destinations as $destination): ?>
                <?php $providerDisabled = $destination['provider'] === 'telegram' && !$telegramNetworkEnabled; ?>
                <label><input type="checkbox" name="destination_ids[]" value="<?=(int)$destination['id']?>" <?=$providerDisabled ? 'disabled' : ''?>> <?=Security::e($destination['name'].' — '.$destination['provider'])?></label>
                <?php endforeach; ?>
                <?php if (!$destinations): ?><p>هنوز مقصد فعالی ذخیره نشده است.</p><?php endif; ?>
            </fieldset>
            <button type="submit" <?=$externalDeliveryEnabled ? '' : 'disabled aria-disabled="true"'?>>قرار دادن در صف</button>
        </fieldset>
    </form>
</section>
<section class="panel">
    <h2>صف و تاریخچه</h2>
    <table><thead><tr><th>محتوا</th><th>زمان</th><th>وضعیت</th><th>تلاش</th><th>خطا</th></tr></thead><tbody>
    <?php foreach ($jobs as $job): ?><tr><td><?=Security::e($job['title'])?></td><td><?=Security::e($job['scheduled_at'])?></td><td><?=Security::e($job['status'])?></td><td><?=(int)$job['attempts']?> / <?=(int)$job['max_attempts']?></td><td><?=Security::e((string)$job['last_error'])?></td></tr><?php endforeach; ?>
    </tbody></table>
</section>
