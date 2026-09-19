<?php
use VazinCMS\Security;

$externalDeliveryEnabled = (bool)($externalDeliveryEnabled ?? false);
require __DIR__.'/partials/head.php';
?>
<section class="hero"><div><span class="eyebrow">Webhooks نسخه ۱.۲</span><h1>رویدادها و ارسال‌های بیرونی</h1><p>اتصال امضاشده و قابل‌ردیابی برای ربات‌ها، درگاه پرداخت و سرویس‌های Vazin Online.</p></div></section>
<?php if (!$externalDeliveryEnabled): ?>
<div class="alert error">
    تحویل بیرونی خاموش است؛ آزمایش Webhook، تلاش مجدد و پردازش ارسال‌ها غیرفعال‌اند. ساخت و ویرایش مقصد فقط تنظیمات را ذخیره می‌کند.
    فعال‌سازی نیازمند <code dir="ltr">EXTERNAL_DELIVERY_ENABLED=true</code> است.
</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?=Security::e($error)?></div><?php endif; ?>
<?php if ($newSecret): ?><div class="card"><h2>راز امضای جدید</h2><p>این راز فقط همین یک‌بار نمایش داده می‌شود.</p><code dir="ltr"><?=Security::e($newSecret)?></code></div><?php endif; ?>
<section class="grid two">
    <form class="card" method="post">
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <input type="hidden" name="action" value="create">
        <h2>مقصد جدید</h2>
        <label>نام<input name="name" required minlength="3" placeholder="VazinPay Production"></label>
        <label>نشانی HTTPS<input name="url" type="url" required placeholder="https://example.com/webhooks/vazin"></label>
        <fieldset><legend>رویدادها</legend><?php foreach (\VazinCMS\Webhook::events() as $event): ?><label><input type="checkbox" name="events[]" value="<?=Security::e($event)?>"> <span dir="ltr"><?=Security::e($event)?></span></label><?php endforeach; ?></fieldset>
        <button>ساخت مقصد</button>
    </form>
    <div class="card">
        <h2>مقصدها</h2>
        <div class="table-wrap"><table><thead><tr><th>نام</th><th>نشانی</th><th>وضعیت</th><th>آخرین موفق</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach ($endpoints as $endpoint): ?><tr>
            <td><?=Security::e($endpoint['name'])?></td>
            <td dir="ltr"><code><?=Security::e($endpoint['url'])?></code></td>
            <td><?=$endpoint['is_active'] ? 'فعال' : 'غیرفعال'?><?=$externalDeliveryEnabled ? '' : ' · تحویل مسدود'?></td>
            <td><?=Security::e((string)($endpoint['last_success_at'] ?: '—'))?></td>
            <td><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="id" value="<?=(int)$endpoint['id']?>"><button name="action" value="test" <?=$externalDeliveryEnabled ? '' : 'disabled aria-disabled="true"'?>>آزمایش</button><button name="action" value="toggle" class="secondary">تغییر وضعیت</button></form></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
</section>
<section class="card">
    <h2>آخرین ارسال‌ها</h2>
    <div class="table-wrap"><table><thead><tr><th>مقصد</th><th>رویداد</th><th>وضعیت</th><th>تلاش</th><th>پاسخ</th><th>زمان</th><th></th></tr></thead><tbody>
    <?php foreach ($deliveries as $delivery): ?><tr>
        <td><?=Security::e($delivery['endpoint_name'])?></td>
        <td dir="ltr"><?=Security::e($delivery['event_type'])?></td>
        <td><?=Security::e($delivery['status'])?></td>
        <td><?=(int)$delivery['attempt_count']?></td>
        <td><?=Security::e((string)($delivery['response_status'] ?: '—'))?></td>
        <td><?=Security::e($delivery['created_at'])?></td>
        <td><?php if (in_array($delivery['status'], ['failed','dead'], true)): ?><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="retry"><input type="hidden" name="delivery_id" value="<?=(int)$delivery['id']?>"><button <?=$externalDeliveryEnabled ? '' : 'disabled aria-disabled="true"'?>>تلاش مجدد</button></form><?php endif; ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php require __DIR__.'/partials/foot.php'; ?>
