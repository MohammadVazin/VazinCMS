<?php
use VazinCMS\Security;
$statusLabel=['pending'=>'در انتظار رضایت','opted_in'=>'فعال','paused'=>'موقتاً متوقف','revoked'=>'لغوشده','blocked'=>'مسدودشده'];
$outboxLabel=['pending'=>'در انتظار','processing'=>'در حال ارسال','sent'=>'ارسال‌شده','failed'=>'ناموفق','cancelled'=>'لغوشده'];
?>
<section class="title">
  <div>
    <span class="eyebrow">White-label Travel & Visa</span>
    <h1>هشدارهای رضایت‌محور Telegram</h1>
    <p>پیام‌ها فقط پس از opt-in ارسال می‌شوند و هرگز شامل گذرنامه، مدارک، یادداشت داخلی یا مشخصات مسافر نیستند.</p>
  </div>
  <a class="button secondary" href="/admin/telegram">تنظیم اتصال Telegram</a>
</section>

<?php if($message):?><div class="notice success"><?=Security::e($message)?></div><?php endif;?>
<?php if($error):?><div class="notice error"><?=Security::e($error)?></div><?php endif;?>
<?php if(!$alertsEnabled):?><div class="notice warning"><strong>ارسال غیرفعال است.</strong> تا وقتی `EXTERNAL_DELIVERY_ENABLED`، `TELEGRAM_NETWORK_ENABLED` و `TELEGRAM_ALERT_DELIVERY_ENABLED` همگی صریحاً true نباشند، هیچ پیام یا پیوند رضایتی ساخته نمی‌شود.</div><?php endif;?>

<section class="panel">
  <h2>آمادگی و پیش‌نمایش هشدارها</h2>
  <p>این بخش فقط خواندنی است: نه پیام، نه اشتراک و نه پیوند رضایت در آن ساخته نمی‌شود. مقدارهای محرمانه و شناسهٔ چت هم نمایش داده نمی‌شوند.</p>
  <div class="table-wrap" role="region" tabindex="0" aria-label="گیت‌های تحویل"><table><thead><tr><th>گیت</th><th>کلید عملیاتی</th><th>وضعیت</th></tr></thead><tbody><?php foreach($deliveryGates as$gate):?><tr><td><?=Security::e($gate['label'])?></td><td><code dir="ltr"><?=Security::e($gate['key'])?></code></td><td><span class="status <?=$gate['enabled']?'active':'closed'?>"><?=$gate['enabled']?'باز':'قفل‌شده'?></span></td></tr><?php endforeach;?></tbody></table></div>
  <h3>آمادگی اتصال ربات</h3>
  <div class="table-wrap" role="region" tabindex="0" aria-label="آمادگی اتصال ربات"><table><thead><tr><th>اتصال</th><th>نام کاربری ربات</th><th>Webhook و اتصال</th><th>آماده برای رضایت</th></tr></thead><tbody><?php foreach($connectionReadiness as$connection):?><tr><td><?=Security::e($connection['name'])?></td><td dir="ltr"><?=$connection['username']!==''?'@'.Security::e($connection['username']):'—'?></td><td><span class="status <?=$connection['webhook_ready']?'active':'closed'?>"><?=$connection['webhook_ready']?'آماده':'ناقص یا غیرفعال'?></span></td><td><span class="status <?=$connection['ready']?'active':'closed'?>"><?=$connection['ready']?'بله':'خیر'?></span></td></tr><?php endforeach;?><?php if(!$connectionReadiness):?><tr><td colspan="4" class="muted">اتصال رباتی ثبت نشده است.</td></tr><?php endif;?></tbody></table></div>
</section>

<section class="panel">
  <h2>نمونهٔ پیامِ حریم‌خصوصی‌محور</h2>
  <p>این‌ها پیش‌نمایش ثابت برای گفت‌وگو با آژانس‌اند؛ شناسهٔ پرونده، نام، گذرنامه، مدرک، یادداشت داخلی یا دادهٔ پرداخت واقعی در آن‌ها نیست.</p>
  <div class="platform-grid">
    <?php foreach($alertPreview as$preview):?><article class="platform-card"><strong><?=Security::e($preview['recipient'])?></strong><p><b><?=Security::e($preview['event'])?></b><br><?=Security::e($preview['message'])?></p></article><?php endforeach;?>
  </div>
</section>

<section class="grid stats">
  <article><span>اتصال‌های ربات</span><b><?=(int)count($connections)?></b></article>
  <article><span>اشتراک فعال</span><b><?=(int)count(array_filter($subscriptions,static fn(array $s):bool=>$s['status']==='opted_in'))?></b></article>
  <article><span>صف در انتظار</span><b><?=(int)count(array_filter($outbox,static fn(array $o):bool=>$o['status']==='pending'))?></b></article>
  <article><span>تحویل</span><b><?=$alertsEnabled?'فعال':'قفل‌شده'?></b></article>
</section>

<section class="panel">
  <h2>فعال‌سازی مدیر</h2>
  <p>مدیر باید شخصاً پیوند یک‌بارمصرف را باز کند و ربات همان آژانس را Start کند. «شناسهٔ چت» به‌تنهایی رضایت محسوب نمی‌شود.</p>
  <?php if($connections):?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="manager_optin"><label>اتصال ربات<select name="connection_id" required><?php foreach($connections as$c):?><option value="<?=(int)$c['id']?>" <?=((int)$c['is_enabled']!==1||(string)$c['webhook_status']!=='active'||(string)$c['bot_username']==='')?'disabled':''?>><?=Security::e($c['name'])?> · <?=Security::e((string)($c['bot_username']?:'بدون username'))?> · <?=((int)$c['is_enabled']===1&&(string)$c['webhook_status']==='active')?'فعال':'غیرفعال'?></option><?php endforeach;?></select></label><label>زبان<select name="locale"><option value="fa">فارسی</option><option value="ru">Русский</option><option value="en">English</option></select></label><button <?=!$alertsEnabled?'disabled':''?>>ساخت پیوند رضایت</button></form><?php else:?><p class="muted">ابتدا یک ربات و Webhook فعال را در تنظیمات Telegram ثبت کنید.</p><?php endif;?>
  <?php if($optInUrl):?><div class="notice success"><strong>این پیوند را فقط به مدیر مجاز بدهید:</strong><br><a dir="ltr" href="<?=Security::e($optInUrl)?>" rel="noopener noreferrer" target="_blank"><?=Security::e($optInUrl)?></a><br><small>پس از استفاده یا ۲۴ ساعت، دیگر معتبر نیست.</small></div><?php endif;?>
</section>

<section class="panel">
  <h2>اشتراک‌ها</h2>
  <div class="table-wrap" role="region" tabindex="0" aria-label="اشتراک‌های Telegram"><table><thead><tr><th>گیرنده</th><th>اتصال</th><th>منبع</th><th>چت</th><th>وضعیت</th><th>رضایت</th><th>عملیات</th></tr></thead><tbody><?php foreach($subscriptions as$s):?><tr><td><?=Security::e($s['recipient_type']==='manager'?'مدیر':'مشتری')?></td><td><?=Security::e($s['connection_name'])?></td><td><code><?=Security::e($s['source_type'])?>:<?=((int)$s['source_id'])?></code></td><td dir="ltr"><?=Security::e($s['chat_display'])?></td><td><?=Security::e($statusLabel[$s['status']]??$s['status'])?></td><td><?=Security::e((string)($s['consented_at']?:'—'))?></td><td><?php if(in_array($s['status'],['pending','opted_in','paused'],true)):?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="subscription_status"><input type="hidden" name="subscription_id" value="<?=(int)$s['id']?>"><?php if($s['status']==='opted_in'):?><button class="secondary" name="status" value="paused">توقف</button><?php endif;?><button class="danger" name="status" value="revoked">لغو</button></form><?php else:?><span class="muted">—</span><?php endif;?></td></tr><?php endforeach;?><?php if(!$subscriptions):?><tr><td colspan="7" class="muted">هنوز اشتراکی ثبت نشده است.</td></tr><?php endif;?></tbody></table></div>
</section>

<section class="panel">
  <h2>صف تحویل</h2>
  <form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>"><input type="hidden" name="action" value="process_queue"><button <?=!$alertsEnabled?'disabled':''?>>پردازش امن صف</button><small>زمان‌بند سرور این کار را خودکار انجام می‌دهد؛ این دکمه فقط برای بررسی عملیاتی است.</small></form>
  <div class="table-wrap" role="region" tabindex="0" aria-label="صف تحویل Telegram"><table><thead><tr><th>رویداد</th><th>گیرنده</th><th>اتصال</th><th>وضعیت</th><th>تلاش</th><th>خطای امن</th><th>زمان</th></tr></thead><tbody><?php foreach($outbox as$o):?><tr><td><code><?=Security::e($o['event_type'])?></code></td><td><?=Security::e($o['recipient_type']==='manager'?'مدیر':'مشتری')?></td><td><?=Security::e($o['connection_name'])?></td><td><?=Security::e($outboxLabel[$o['status']]??$o['status'])?></td><td><?=(int)$o['attempts']?>/<?=(int)$o['max_attempts']?></td><td><code><?=Security::e((string)($o['last_error']?:'—'))?></code></td><td><?=Security::e((string)$o['created_at'])?></td></tr><?php endforeach;?><?php if(!$outbox):?><tr><td colspan="7" class="muted">صفی وجود ندارد.</td></tr><?php endif;?></tbody></table></div>
</section>
