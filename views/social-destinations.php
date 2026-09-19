<?php
use VazinCMS\Security;

$title = 'شبکه‌های اجتماعی';
$externalDeliveryEnabled = (bool)($externalDeliveryEnabled ?? false);
$telegramNetworkEnabled = (bool)($telegramNetworkEnabled ?? false);
?>
<section class="panel">
    <h1>اتصال شبکه‌ها</h1>
    <p>Token رمزگذاری می‌شود و بعد از ذخیره دوباره نمایش داده نخواهد شد. ذخیرهٔ مقصد به‌تنهایی هیچ پیامی ارسال نمی‌کند.</p>
    <?php if (!$externalDeliveryEnabled): ?>
    <div class="alert error">
        تحویل بیرونی در سطح سیستم خاموش است. مقصدها را می‌توانید برای آماده‌سازی ذخیره کنید، اما تا تنظیم صریح
        <code dir="ltr">EXTERNAL_DELIVERY_ENABLED=true</code> هیچ ارسالی انجام نمی‌شود.
    </div>
    <?php elseif (!$telegramNetworkEnabled): ?>
    <div class="alert">شبکهٔ Telegram خاموش است و مقصدهای Telegram حتی در صورت ذخیره‌شدن ارسال نخواهند داشت.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?=Security::e($error)?></div><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <label>نام<input name="name" required></label>
        <label>نوع<select name="provider"><option value="telegram">Telegram Channel</option><option value="vk">VK</option><option value="x">X</option><option value="webhook">Webhook</option></select></label>
        <label>شناسه کانال/حساب<input name="account_ref" placeholder="@channel یا owner_id"></label>
        <label>Webhook HTTPS<input name="endpoint" type="url"></label>
        <label>Token یا Webhook Secret<input name="token" type="password" autocomplete="new-password" required></label>
        <button type="submit">ذخیره امن</button>
    </form>
</section>
<section class="panel">
    <h2>مقصدهای ذخیره‌شده</h2>
    <table><thead><tr><th>نام</th><th>نوع</th><th>حساب</th><th>تحویل</th><th>عملیات</th></tr></thead><tbody>
    <?php foreach ($destinations as $destination): ?>
    <?php $deliveryReady = $externalDeliveryEnabled && ($destination['provider'] !== 'telegram' || $telegramNetworkEnabled); ?>
    <tr>
        <td><?=Security::e($destination['name'])?></td>
        <td><?=Security::e($destination['provider'])?></td>
        <td><?=Security::e((string)$destination['account_ref'])?></td>
        <td><?=$deliveryReady ? 'مجاز با برنامهٔ انتشار' : 'غیرفعال در سطح سیستم'?></td>
        <td><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$destination['id']?>"><button>حذف</button></form></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</section>
