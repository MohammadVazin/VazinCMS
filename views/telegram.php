<?php
use VazinCMS\Security;

$locales = [
    'fa'=>'فارسی', 'ar'=>'العربية', 'en'=>'English', 'ru'=>'Русский', 'tr'=>'Türkçe',
    'hy'=>'Հայերեն', 'kk'=>'Қазақша', 'tg'=>'Тоҷикӣ', 'zh'=>'中文',
];
$externalDeliveryEnabled = (bool)($externalDeliveryEnabled ?? false);
$telegramNetworkEnabled = (bool)($telegramNetworkEnabled ?? false);
?>
<section class="admin-title">
    <div>
        <span class="eyebrow">همگام‌سازی کنترل‌شده</span>
        <h1>Telegram</h1>
        <p>تاریخچه را می‌توان آفلاین وارد کرد؛ Webhook، Web App و ارسال پیام فقط با فعال‌سازی صریح شبکه کار می‌کنند.</p>
    </div>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <input type="hidden" name="action" value="process_queue">
        <button <?=$telegramNetworkEnabled ? '' : 'disabled aria-disabled="true"'?>>پردازش صف اکنون</button>
    </form>
</section>

<?php if (!$telegramNetworkEnabled): ?>
<div class="alert error">
    شبکهٔ Telegram خاموش است؛ ثبت Webhook، پیام آزمایشی، پردازش صف و Web App غیرفعال‌اند.
    برای فعال‌سازی آگاهانه، هر دو مقدار <code dir="ltr">EXTERNAL_DELIVERY_ENABLED=true</code> و
    <code dir="ltr">TELEGRAM_NETWORK_ENABLED=true</code> را تنظیم کنید.
</div>
<?php elseif (!$externalDeliveryEnabled): ?>
<div class="alert error">تحویل بیرونی خاموش است و عملیات شبکه انجام نمی‌شود.</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?=Security::e($error)?></div><?php endif; ?>
<?php if ($message): ?><div class="alert success"><?=Security::e($message)?></div><?php endif; ?>
<div class="alert">
    Bot API تاریخچهٔ قدیمی کانال را ارائه نمی‌کند. برای اسکن کل کانال، خروجی JSON تلگرام را وارد کنید یا
    خروجی استاندارد یک اتصال MTProto احرازشده را بدهید؛ بازواردکردن فایل همان پست‌ها را به‌روزرسانی می‌کند.
</div>

<section class="content-layout">
    <form class="editor-card" method="post">
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <input type="hidden" name="action" value="create">
        <div class="card-head"><div><h2>اتصال جدید</h2><p>اتصال تازه به‌صورت غیرفعال، ورودی به سایت و پیش‌نویس ذخیره می‌شود.</p></div></div>
        <div class="form-grid">
            <label>نام اتصال<input name="name" required></label>
            <label>شناسهٔ عددی کانال<input dir="ltr" name="chat_id" required placeholder="-1001234567890"></label>
            <label>Bot Token<input dir="ltr" type="password" name="bot_token" required autocomplete="new-password"></label>
            <label>گفت‌وگوی مدیر<input dir="ltr" name="manager_chat_id" placeholder="123456789"></label>
            <label>حالت
                <select name="sync_mode">
                    <option value="telegram_to_site">از Telegram به سایت</option>
                    <option value="site_to_telegram">از سایت به Telegram</option>
                    <option value="bidirectional">دوطرفه</option>
                </select>
            </label>
            <label>وضعیت پست ورودی
                <select name="incoming_status">
                    <option value="draft">پیش‌نویس برای بازبینی</option>
                    <option value="published">انتشار مستقیم</option>
                </select>
            </label>
            <label>زبان<select name="locale"><?php foreach ($locales as $code=>$label): ?><option value="<?=$code?>"><?=Security::e($label)?></option><?php endforeach; ?></select></label>
        </div>
        <label class="switch-row"><input type="checkbox" name="auto_publish_site" value="1" <?=$telegramNetworkEnabled ? '' : 'disabled'?>><span class="switch"></span><span><b>انتشار خودکار مطالب سایت در Telegram</b></span></label>
        <label class="switch-row"><input type="checkbox" name="web_app_enabled" value="1" <?=$telegramNetworkEnabled ? '' : 'disabled'?>><span class="switch"></span><span><b>نسخهٔ مدیریت داخل Telegram</b></span></label>
        <label class="switch-row"><input type="checkbox" name="is_enabled" value="1" <?=$telegramNetworkEnabled ? '' : 'disabled'?>><span class="switch"></span><span><b>فعال‌کردن اتصال شبکه</b></span></label>
        <button>ذخیره اتصال</button>
    </form>

    <aside class="list-card">
        <div class="card-head"><div><h2>اتصال‌ها</h2><p>توکن‌ها هرگز در پنل نمایش داده نمی‌شوند.</p></div></div>
        <?php foreach ($connections as $connection): ?>
        <article class="page-item">
            <div class="page-main">
                <strong><?=Security::e($connection['name'])?> <?=($connection['bot_username'] ?? '') !== '' ? '@'.Security::e($connection['bot_username']) : ''?></strong>
                <code><?=Security::e($connection['chat_id'])?></code>
                <span class="status <?=Security::e($connection['webhook_status'])?>"><?=Security::e($connection['webhook_status'])?></span>
                <small><?=Security::e($connection['sync_mode'].' · آخرین همگام‌سازی: '.($connection['last_sync_at'] ?: '—'))?></small>
                <?php if ($connection['last_error']): ?><span class="alert error"><?=Security::e($connection['last_error'])?></span><?php endif; ?>
            </div>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
                <input type="hidden" name="connection_id" value="<?=(int)$connection['id']?>">
                <button name="action" value="register" <?=$telegramNetworkEnabled ? '' : 'disabled aria-disabled="true"'?>>ثبت Webhook</button>
                <button class="button secondary" name="action" value="test_notice" <?=$telegramNetworkEnabled ? '' : 'disabled aria-disabled="true"'?>>پیام آزمایشی</button>
            </form>
            <details>
                <summary>تنظیم اتصال</summary>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="connection_id" value="<?=(int)$connection['id']?>">
                    <label>Bot Token جدید (اختیاری)<input type="password" name="bot_token"></label>
                    <label>شناسه گفت‌وگوی مدیر<input name="manager_chat_id" value="<?=Security::e($connection['manager_chat_id'])?>"></label>
                    <label>حالت
                        <select name="sync_mode">
                            <?php foreach (['telegram_to_site'=>'از Telegram به سایت','site_to_telegram'=>'از سایت به Telegram','bidirectional'=>'دوطرفه'] as $value=>$label): ?>
                            <option value="<?=$value?>" <?=$connection['sync_mode'] === $value ? 'selected' : ''?>><?=$label?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>وضعیت ورودی
                        <select name="incoming_status">
                            <option value="draft" <?=$connection['incoming_status'] === 'draft' ? 'selected' : ''?>>پیش‌نویس</option>
                            <option value="published" <?=$connection['incoming_status'] === 'published' ? 'selected' : ''?>>انتشار مستقیم</option>
                        </select>
                    </label>
                    <label>زبان<select name="locale"><?php foreach ($locales as $code=>$label): ?><option value="<?=$code?>" <?=$connection['locale'] === $code ? 'selected' : ''?>><?=Security::e($label)?></option><?php endforeach; ?></select></label>
                    <label><input type="checkbox" name="auto_publish_site" value="1" <?=$connection['auto_publish_site'] ? 'checked' : ''?> <?=$telegramNetworkEnabled ? '' : 'disabled'?>> انتشار خودکار</label>
                    <label><input type="checkbox" name="web_app_enabled" value="1" <?=$connection['web_app_enabled'] ? 'checked' : ''?> <?=$telegramNetworkEnabled ? '' : 'disabled'?>> Web App</label>
                    <label><input type="checkbox" name="is_enabled" value="1" <?=$connection['is_enabled'] ? 'checked' : ''?> <?=$telegramNetworkEnabled ? '' : 'disabled'?>> فعال</label>
                    <button>به‌روزرسانی</button>
                </form>
            </details>
            <?php if ($appUrl && $telegramNetworkEnabled && (int)$connection['web_app_enabled'] === 1): ?>
            <a href="<?=Security::e($appUrl.'/telegram/webapp/'.$connection['webhook_key'])?>" target="_blank" rel="noopener">نشانی Web App</a>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
        <?php if (!$connections): ?><div class="empty-state">هنوز اتصالی ساخته نشده است.</div><?php endif; ?>
    </aside>
</section>

<?php if ($connections): ?>
<section class="content-layout">
    <form class="editor-card" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <input type="hidden" name="action" value="import">
        <h2>واردکردن کل تاریخچه</h2>
        <label>اتصال<select name="connection_id"><?php foreach ($connections as $connection): ?><option value="<?=(int)$connection['id']?>"><?=Security::e($connection['name'])?></option><?php endforeach; ?></select></label>
        <label>نوع فایل<select name="source_type"><option value="telegram_export">Telegram Desktop result.json</option><option value="mtproto">خروجی استاندارد MTProto</option></select></label>
        <label>فایل JSON<input type="file" name="history_json" accept="application/json,.json" required></label>
        <button>همگام‌سازی همهٔ پیام‌ها</button>
    </form>
    <form class="editor-card" method="post">
        <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">
        <input type="hidden" name="action" value="map_admin">
        <h2>دسترسی Web App مدیر</h2>
        <label>اتصال<select name="connection_id"><?php foreach ($connections as $connection): ?><option value="<?=(int)$connection['id']?>"><?=Security::e($connection['name'])?></option><?php endforeach; ?></select></label>
        <label>شناسه عددی کاربر Telegram<input dir="ltr" name="telegram_user_id" required></label>
        <label>مدیر CMS<select name="cms_user_id"><?php foreach ($users as $cmsUser): ?><option value="<?=(int)$cmsUser['id']?>"><?=Security::e($cmsUser['name'].' · '.$cmsUser['email'])?></option><?php endforeach; ?></select></label>
        <button>ثبت دسترسی</button>
    </form>
</section>
<?php endif; ?>

<section class="list-card"><h2>آخرین واردسازی‌ها</h2><?php foreach ($imports as $item): ?><article class="page-item"><div class="page-main"><strong><?=Security::e($item['connection_name'])?></strong><span><?=Security::e($item['source_type'].' · '.$item['status'])?></span><small>بررسی <?=(int)$item['scanned_count']?> · جدید <?=(int)$item['created_count']?> · به‌روز <?=(int)$item['updated_count']?> · خطا <?=(int)$item['error_count']?></small></div></article><?php endforeach; ?></section>
<section class="list-card"><h2>صف انتشار Telegram</h2><?php foreach ($outbox as $item): ?><article class="page-item"><div class="page-main"><strong><?=Security::e($item['page_title'] ?: $item['action'])?></strong><span><?=Security::e($item['connection_name'].' · '.$item['status'])?></span><small><?=Security::e($item['last_error'] ?: $item['created_at'])?></small></div></article><?php endforeach; ?></section>
<section class="list-card"><h2>آخرین پیام‌های همگام‌شده</h2><?php foreach ($messages as $item): ?><article class="page-item"><div class="page-main"><strong><?=Security::e($item['page_title'] ?: ('پیام '.$item['telegram_message_id']))?></strong><span><?=Security::e($item['connection_name'].' · '.$item['direction'].' · '.$item['status'])?></span><small><?=Security::e($item['message_date'])?></small></div></article><?php endforeach; ?></section>
