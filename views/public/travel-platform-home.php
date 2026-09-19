<?php
use VazinCMS\Security;

$ru=$locale==='ru';
$en=$locale==='en';
$copy=$ru?[
  'eyebrow'=>'WHITE-LABEL TRAVEL PLATFORM',
  'title'=>'Ваше туристическое агентство. Ваш бренд. Одна профессиональная платформа.',
  'text'=>'Vazin Travel даёт лицензированным агентствам собственный сайт, рабочее место команды, интеграции по договору и прозрачный путь от заявки до выдачи.',
  'primary'=>'Запросить демо и условия лицензии',
  'secondary'=>'Посмотреть модель подключений',
  'console'=>'Рабочее место агентства',
  'sections'=>[
    ['Бренд без компромиссов','Домен, язык, валюта, правила, поддержка и юридический продавец принадлежат агентству.'],
    ['Только договорные подключения','GDS, консолидаторы, отели, affiliate и channel manager подключаются отдельно для каждого агентства.'],
    ['Операции, а не витрина','Котировка, повторная проверка цены, выдача, ваучер, изменение, возврат и журнал действий имеют отдельные статусы.'],
  ],
  'flowTitle'=>'Готово к реальному workflow, а не к скрейпингу',
  'flow'=>[
    ['01','Режим продаж','Lead-only, affiliate, ручное исполнение или live booking включается на уровне агентства.'],
    ['02','Источник предложения','Каждый вариант хранит поставщика, момент проверки, правила отмены и ответственного продавца.'],
    ['03','Операции','Команда видит очередь выдачи, ошибки поставщика, изменения и возвраты.'],
    ['04','Уведомления','Менеджер и клиент подключают Telegram добровольно; паспортные данные в сообщения не попадают.'],
  ],
  'why'=>'Почему агентству не нужен очередной маркетплейс',
  'whyText'=>'Платформа не маскируется под продавца билетов. Она делает технологию агентства заметной, управляемой и переносимой на его домен.',
  'ctaTitle'=>'Завтра можно показать агентству не идею, а рабочую основу.',
  'ctaText'=>'Создайте демо-бренд, выберите режим продаж и подготовьте первый договорный connector.',
  'cta'=>'Открыть заявку агентства',
]:($en?[
  'eyebrow'=>'WHITE-LABEL TRAVEL PLATFORM',
  'title'=>'Your travel business. Your brand. One professional operating system.',
  'text'=>'Vazin Travel gives licensed travel businesses their own storefront, team workspace, contract-based integrations and a transparent path from request to fulfilment.',
  'primary'=>'Request demo & licensing terms','secondary'=>'See the connection model','console'=>'Agency workspace',
  'sections'=>[['Brand without compromise','The agency owns its domain, language, currency, policies, support and legal seller identity.'],['Contract-only integrations','GDS, consolidators, stays, affiliate channels and channel managers are isolated per agency.'],['Operations, not a brochure','Quote, revalidation, issuance, voucher, change, refund and audit each have their own state.']],
  'flowTitle'=>'Ready for a real workflow, never scraping',
  'flow'=>[['01','Sales mode','Lead-only, affiliate redirect, manual fulfilment or live booking are chosen per agency.'],['02','Offer provenance','Every offer retains its source, last revalidation, cancellation policy and responsible seller.'],['03','Operations','Teams see issuance queues, supplier failures, changes and refunds.'],['04','Alerts','Managers and customers opt in to Telegram; passport data never goes in a notification.']],
  'why'=>'Why an agency does not need another marketplace',
  'whyText'=>'The platform never pretends to be the ticket seller. It makes each agency’s own technology visible, manageable and portable to its own domain.',
  'ctaTitle'=>'Tomorrow, show an agency a working foundation instead of a slide deck.',
  'ctaText'=>'Create a demo brand, choose a sales mode and prepare the first contract-backed connector.',
  'cta'=>'Open agency request',
]:[
  'eyebrow'=>'پلتفرم وایت‌لیبل سفر',
  'title'=>'کسب‌وکار سفر شما، برند شما، یک سیستم عملیاتی حرفه‌ای.',
  'text'=>'Vazin Travel به آژانس‌ها و کسب‌وکارهای دارای مجوز، سایت اختصاصی، پنل تیم، اتصال‌های قراردادی و مسیر شفاف از درخواست تا انجام خدمت می‌دهد.',
  'primary'=>'درخواست دمو و شرایط لایسنس','secondary'=>'مدل اتصال‌ها را ببینید','console'=>'فضای کاری آژانس',
  'sections'=>[['برند بدون مصالحه','دامنه، زبان، ارز، قوانین، پشتیبانی و فروشندهٔ حقوقی متعلق به خود آژانس است.'],['اتصال فقط با قرارداد','GDS، کانسالیدیتور، هتل، افیلیت و channel manager برای هر آژانس جداگانه و ایزوله می‌شوند.'],['عملیات، نه ویترین','قیمت پیشنهادی، اعتبارسنجی دوباره، صدور، واچر، تغییر، استرداد و لاگ هرکدام وضعیت مستقل دارند.']],
  'flowTitle'=>'آماده برای workflow واقعی، نه اسکرپ‌کردن',
  'flow'=>[['۰۱','حالت فروش','lead-only، ارجاع افیلیت، انجام دستی یا رزرو زنده در سطح آژانس انتخاب می‌شود.'],['۰۲','منبع پیشنهاد','هر پیشنهاد، منبع، زمان revalidate، قانون لغو و فروشندهٔ مسئول خودش را دارد.'],['۰۳','عملیات','تیم صف صدور، خطای تأمین‌کننده، تغییر و بازگشت را در پنل می‌بیند.'],['۰۴','هشدار','مدیر و کاربر فقط با رضایت خود تلگرام را فعال می‌کنند؛ هیچ پاسپورتی در پیام نمی‌رود.']],
  'why'=>'چرا آژانس به یک marketplace دیگر نیاز ندارد',
  'whyText'=>'پلتفرم هرگز خودش را فروشندهٔ بلیت جا نمی‌زند؛ فناوری خود آژانس را روی دامنهٔ خودش قابل‌مدیریت و قابل‌انتقال می‌کند.',
  'ctaTitle'=>'فردا به‌جای یک ایده، یک زیربنای واقعی به آژانس نشان بدهید.',
  'ctaText'=>'برند دمو بسازید، حالت فروش را انتخاب کنید و نخستین connector قراردادی را آماده کنید.',
  'cta'=>'باز کردن درخواست آژانس',
]);
?>
<div class="platform-shell">
  <section class="platform-hero">
    <div>
      <span class="platform-eyebrow"><?=Security::e($copy['eyebrow'])?></span>
      <h1><?=Security::e($copy['title'])?></h1>
      <p><?=Security::e($copy['text'])?></p>
      <div class="platform-actions">
        <a class="primary" href="/<?=Security::e($locale)?>/agency"><?=Security::e($copy['primary'])?></a>
        <a href="#connection-model"><?=Security::e($copy['secondary'])?></a>
      </div>
    </div>
    <aside class="platform-console" aria-label="<?=Security::e($copy['console'])?>">
      <div class="console-head"><strong><?=Security::e($copy['console'])?></strong><span class="platform-status"><?=Security::e($ru?'Готово к настройке':($en?'Ready to configure':'آمادهٔ پیکربندی'))?></span></div>
      <div class="console-row"><span><?=Security::e($ru?'Бренд и домен':($en?'Brand and domain':'برند و دامنه'))?></span><b class="platform-status"><?=Security::e($ru?'изолированы':($en?'isolated':'ایزوله'))?></b></div>
      <div class="console-row"><span><?=Security::e($ru?'Режим продаж':($en?'Sales mode':'حالت فروش'))?></span><b><?=Security::e($ru?'выбирается агентством':($en?'agency-selected':'انتخاب آژانس'))?></b></div>
      <div class="console-row"><span><?=Security::e($ru?'Коннекторы':($en?'Connectors':'اتصال‌ها'))?></span><b><?=Security::e($ru?'по договору':($en?'contract-backed':'قراردادی'))?></b></div>
      <div class="console-row"><span>Telegram</span><b><?=Security::e($ru?'по согласию':($en?'opt-in':'اختیاری'))?></b></div>
    </aside>
  </section>

  <section class="platform-section" id="platform-features">
    <span class="platform-kicker">01</span>
    <h2><?=Security::e($ru?'Основа, которую агентство может показать клиенту':($en?'A foundation an agency can show its customer':'زیربنایی که آژانس می‌تواند به مشتری خود نشان دهد'))?></h2>
    <div class="platform-grid">
      <?php foreach($copy['sections'] as $item):?><article class="platform-card"><h3><?=Security::e($item[0])?></h3><p><?=Security::e($item[1])?></p></article><?php endforeach;?>
    </div>
  </section>

  <span id="connection-model"></span>
  <section class="platform-section" id="sales-modes">
    <span class="platform-kicker">02</span>
    <h2><?=Security::e($copy['flowTitle'])?></h2>
    <div class="platform-timeline">
      <?php foreach($copy['flow'] as $item):?><article class="platform-timeline-item"><b><?=Security::e($item[0])?></b><h3><?=Security::e($item[1])?></h3><p><?=Security::e($item[2])?></p></article><?php endforeach;?>
    </div>
  </section>

  <section class="platform-section">
    <span class="platform-kicker">03</span>
    <h2><?=Security::e($copy['why'])?></h2>
    <p class="platform-section-intro"><?=Security::e($copy['whyText'])?></p>
    <div class="platform-grid">
      <article class="platform-card"><strong><?=Security::e($ru?'Места проживания':($en?'Stays':'اقامت'))?></strong><p><?=Security::e($ru?'Отели, хостелы и объекты размещения с реальными правилами, ваучером и источником инвентаря.':($en?'Hotels, hostels and stays with real policies, vouchers and inventory provenance.':'هتل، هاستل و اقامتگاه با قوانین واقعی، واچر و منبع مشخص موجودی.'))?></p></article>
      <article class="platform-card"><strong><?=Security::e($ru?'Авиабилеты':($en?'Flights':'پرواز'))?></strong><p><?=Security::e($ru?'Только через контракт агентства: повторная проверка, очередь выдачи, изменения и возвраты.':($en?'Only through the agency contract: revalidation, issuance queue, changes and refunds.':'فقط از مسیر قرارداد آژانس: revalidate، صف صدور، تغییر و استرداد.'))?></p></article>
      <article class="platform-card"><strong><?=Security::e($ru?'B2B и корпоративные поездки':($en?'B2B and corporate travel':'B2B و سفر سازمانی'))?></strong><p><?=Security::e($ru?'Роли, лимиты, согласования и отчётность остаются в панели самого агентства.':($en?'Roles, limits, approvals and reporting remain in the agency workspace.':'نقش‌ها، سقف‌ها، تأییدها و گزارش در فضای خود آژانس می‌ماند.'))?></p></article>
    </div>
  </section>

  <section class="platform-section" id="licensing">
    <span class="platform-kicker">04</span>
    <h2><?=Security::e($ru?'Лицензия на платформу, а не продажа туров от имени Vazin':($en?'Platform licensing, not travel sold by Vazin':'لایسنس پلتفرم، نه فروش خدمات سفر توسط Vazin'))?></h2>
    <p class="platform-section-intro"><?=Security::e($ru?'Vazin предоставляет программную платформу. Агентство остаётся владельцем бренда, отношений с клиентом, договоров с поставщиками и юридическим продавцом.':($en?'Vazin licenses the software platform. The agency remains the brand owner, customer relationship owner, supplier contract holder and legal seller.':'Vazin نرم‌افزار و زیرساخت را لایسنس می‌کند؛ آژانس صاحب برند، رابطه با مشتری، قرارداد تأمین‌کننده و فروشندهٔ حقوقی باقی می‌ماند.'))?></p>
    <div class="platform-grid">
      <article class="platform-card"><strong><?=Security::e($ru?'Объём лицензии':($en?'License scope':'محدودهٔ لایسنس'))?></strong><p><?=Security::e($ru?'Определяется по функциям, доменам, языкам, рабочим местам и нужным коннекторам.':($en?'Defined by capabilities, domains, languages, workspaces and required connectors.':'براساس قابلیت‌ها، دامنه‌ها، زبان‌ها، فضای کاری و اتصال‌های موردنیاز تعیین می‌شود.'))?></p></article>
      <article class="platform-card"><strong><?=Security::e($ru?'Активация':($en?'Activation':'فعال‌سازی'))?></strong><p><?=Security::e($ru?'Поставщики и live booking включаются только после договора, credentials агентства и контролируемого теста.':($en?'Providers and live booking activate only after contract, agency credentials and controlled testing.':'تأمین‌کننده و رزرو زنده فقط پس از قرارداد، credential آژانس و آزمون کنترل‌شده فعال می‌شوند.'))?></p></article>
      <article class="platform-card"><strong><?=Security::e($ru?'Ваш клиент — ваш':($en?'Your customer stays yours':'مشتری شما، متعلق به شما'))?></strong><p><?=Security::e($ru?'Клиент видит бренд, домен, поддержку и правила агентства, а не магазин Vazin.':($en?'The customer sees the agency brand, domain, support and policies — not a Vazin storefront.':'مشتری برند، دامنه، پشتیبانی و قوانین آژانس را می‌بیند، نه فروشگاه Vazin.'))?></p></article>
    </div>
  </section>

  <section class="platform-callout">
    <div><h2><?=Security::e($copy['ctaTitle'])?></h2><p><?=Security::e($copy['ctaText'])?></p></div>
    <a class="platform-button" href="/<?=Security::e($locale)?>/agency"><?=Security::e($copy['cta'])?></a>
  </section>
</div>
<?php require __DIR__.'/foot.php'; ?>
