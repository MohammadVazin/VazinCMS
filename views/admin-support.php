<?php
declare(strict_types=1);
use VazinCMS\Security;
?>
<section class="cms-overview">
  <section class="cms-overview-intro" aria-labelledby="support-home-title">
    <div>
      <p class="cms-kicker">فضای کار / پشتیبانی</p>
      <h1 id="support-home-title">خوش آمدید، <?= Security::e((string)($user['name'] ?? 'همکار')) ?></h1>
      <p>حساب پشتیبانی شما فعال است. برای ورودهای بعدی با وزین آیدی، از گزینهٔ «اتصال Vazin ID» در نوار بالای صفحه استفاده کنید.</p>
      <p>ویرایش محتوا، تنظیمات و افزونه‌ها فقط با دسترسی مدیر سایت مجاز است.</p>
    </div>
  </section>
</section>
