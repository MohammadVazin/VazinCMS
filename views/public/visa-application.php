<?php
use VazinCMS\Security;

$t = static function (string $fa, string $ru, string $en) use ($locale): string {
    return $locale === 'ru' ? $ru : ($locale === 'en' ? $en : $fa);
};
$statusLabels = [
    'new' => $t('جدید', 'Новая', 'New'),
    'awaiting_payment' => $t('منتظر پرداخت', 'Ожидает оплаты', 'Awaiting payment'),
    'paid' => $t('پرداخت‌شده', 'Оплачена', 'Paid'),
    'reviewing' => $t('در حال بررسی', 'На проверке', 'Under review'),
    'documents_required' => $t('نیازمند مدرک', 'Нужны документы', 'Documents required'),
    'processing' => $t('در حال انجام', 'В обработке', 'Processing'),
    'completed' => $t('تکمیل‌شده', 'Завершена', 'Completed'),
    'rejected' => $t('ردشده', 'Отклонена', 'Rejected'),
    'cancelled' => $t('لغوشده', 'Отменена', 'Cancelled'),
];
$applicationUrl = '/' . rawurlencode($locale) . '/order/' . rawurlencode((string) $order['public_id']) . '/application';
$today = gmdate('Y-m-d');
?>
<section class="visa-checkout">
  <div class="section-title">
    <span><?=Security::e($t('فرم امن پرونده', 'Защищённая анкета', 'Secure case form'))?></span>
    <h1><?=Security::e($t('تکمیل اطلاعات درخواست eVisa', 'Заполнение анкеты eVisa', 'Complete your eVisa application'))?></h1>
    <p><?=Security::e($t('اطلاعات گذرنامه و هویتی فقط برای بررسی پرونده رمزگذاری می‌شوند و پس از ثبت دوباره نمایش داده نخواهند شد.', 'Паспортные и личные данные шифруются только для обработки дела и после отправки не показываются снова.', 'Passport and identity data are encrypted for case processing only and are not shown again after submission.'))?></p>
  </div>

  <div class="visa-alert">
    <?=Security::e($t('پرونده', 'Дело', 'Case'))?>
    <strong dir="ltr"><?=Security::e((string) $order['public_id'])?></strong>
    — <?=Security::e($t('وضعیت', 'Статус', 'Status'))?>:
    <strong><?=Security::e($statusLabels[(string) $order['status']] ?? (string) $order['status'])?></strong>
  </div>

  <?php if ($saved): ?>
    <div class="visa-alert success" role="status">
      <b><?=Security::e($t('فرم eVisa با موفقیت ثبت شد.', 'Анкета eVisa успешно отправлена.', 'Your eVisa application was submitted successfully.'))?></b><br>
      <?=Security::e($t('به‌دلیل حفاظت از حریم خصوصی، اطلاعات گذرنامه در این صفحه نمایش داده نمی‌شود.', 'Для защиты конфиденциальности паспортные данные на этой странице не отображаются.', 'For privacy protection, passport data is not displayed on this page.'))?>
    </div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="visa-alert error" role="alert">
      <b><?=Security::e($t('لطفاً موارد زیر را اصلاح کنید:', 'Исправьте следующие поля:', 'Please correct the following fields:'))?></b>
      <?php foreach ($errors as $error): ?><div><?=Security::e($error)?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$editable): ?>
    <div class="visa-alert error" role="alert">
      <?=Security::e($t('این پرونده برای ویرایش فرم eVisa بسته است. در صورت نیاز با آژانس یا پشتیبانی تماس بگیرید.', 'Это дело закрыто для изменения анкеты eVisa. При необходимости обратитесь в агентство или поддержку.', 'This case is closed for eVisa form changes. Contact the agency or support if you need help.'))?>
    </div>
  <?php elseif ($submitted && !$showForm): ?>
    <div class="checkout-actions wide">
      <a class="primary" href="<?=Security::e($applicationUrl . '?replace=1')?>"><?=Security::e($t('ارسال نسخه اصلاح‌شده', 'Отправить исправленную версию', 'Submit a corrected version'))?></a>
      <small><?=Security::e($t('برای اصلاح باید همه اطلاعات را دوباره وارد کنید؛ نسخه قبلی نمایش داده نمی‌شود.', 'Для исправления нужно ввести все данные заново; предыдущая версия не показывается.', 'To correct the application, enter all data again; the previous version is never displayed.'))?></small>
    </div>
  <?php endif; ?>

  <?php if ($showForm): ?>
    <form method="post" action="<?=Security::e($applicationUrl)?>" class="travel-form visa-order-form" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?=Security::e(Security::csrf())?>">

      <fieldset>
        <legend><?=Security::e($t('هویت مطابق گذرنامه', 'Личные данные по паспорту', 'Identity as shown in passport'))?></legend>
        <div class="form-grid">
          <label><?=Security::e($t('نام', 'Имя', 'Given name'))?><input name="given_name" maxlength="100" required autocomplete="given-name"></label>
          <label><?=Security::e($t('نام خانوادگی', 'Фамилия', 'Family name'))?><input name="family_name" maxlength="100" required autocomplete="family-name"></label>
          <label><?=Security::e($t('تاریخ تولد', 'Дата рождения', 'Date of birth'))?><input name="birth_date" type="date" max="<?=Security::e($today)?>" required dir="ltr"></label>
          <label><?=Security::e($t('جنسیت', 'Пол', 'Gender'))?><select name="gender" required><option value="" selected disabled><?=Security::e($t('انتخاب کنید', 'Выберите', 'Select'))?></option><option value="female"><?=Security::e($t('زن', 'Женский', 'Female'))?></option><option value="male"><?=Security::e($t('مرد', 'Мужской', 'Male'))?></option><option value="other"><?=Security::e($t('سایر', 'Другое', 'Other'))?></option></select></label>
          <label><?=Security::e($t('کشور محل تولد', 'Страна рождения', 'Country of birth'))?><input name="birth_country" maxlength="100" required></label>
          <label><?=Security::e($t('شهر محل تولد', 'Город рождения', 'City of birth'))?><input name="birth_city" maxlength="100" required></label>
          <label><?=Security::e($t('تابعیت فعلی', 'Текущее гражданство', 'Current nationality'))?><input name="nationality" maxlength="100" required></label>
          <label><?=Security::e($t('تابعیت دوم', 'Второе гражданство', 'Second nationality'))?><input name="second_nationality" maxlength="100"></label>
        </div>
      </fieldset>

      <fieldset>
        <legend><?=Security::e($t('گذرنامه', 'Паспорт', 'Passport'))?></legend>
        <div class="form-grid">
          <label><?=Security::e($t('شماره گذرنامه', 'Номер паспорта', 'Passport number'))?><input name="passport_number" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9 -]{4,29}" required dir="ltr" autocomplete="off"></label>
          <label><?=Security::e($t('کشور صادرکننده', 'Страна выдачи', 'Issuing country'))?><input name="passport_issuing_country" maxlength="100" required></label>
          <label><?=Security::e($t('تاریخ صدور', 'Дата выдачи', 'Issue date'))?><input name="passport_issued_at" type="date" max="<?=Security::e($today)?>" required dir="ltr"></label>
          <label><?=Security::e($t('تاریخ انقضا', 'Дата окончания', 'Expiry date'))?><input name="passport_expires_at" type="date" min="<?=Security::e($today)?>" required dir="ltr"></label>
          <label class="wide"><?=Security::e($t('مرجع صادرکننده', 'Орган выдачи', 'Issuing authority'))?><input name="passport_issuing_authority" maxlength="190" required></label>
        </div>
      </fieldset>

      <fieldset>
        <legend><?=Security::e($t('محل اقامت و تماس', 'Проживание и контакты', 'Residence and contact'))?></legend>
        <div class="form-grid">
          <label><?=Security::e($t('کشور محل اقامت', 'Страна проживания', 'Country of residence'))?><input name="residence_country" maxlength="100" required></label>
          <label><?=Security::e($t('شهر محل اقامت', 'Город проживания', 'City of residence'))?><input name="residence_city" maxlength="100" required></label>
          <label><?=Security::e($t('ایمیل', 'Электронная почта', 'Email'))?><input name="email" type="email" maxlength="190" required autocomplete="email" dir="ltr"></label>
          <label><?=Security::e($t('شماره تماس', 'Телефон', 'Phone'))?><input name="phone" type="tel" maxlength="64" required autocomplete="tel" inputmode="tel" dir="ltr"></label>
          <label class="wide"><?=Security::e($t('نشانی کامل محل اقامت', 'Полный адрес проживания', 'Full residential address'))?><textarea name="residence_address" maxlength="500" rows="3" required autocomplete="street-address"></textarea></label>
        </div>
      </fieldset>

      <fieldset>
        <legend><?=Security::e($t('برنامه سفر', 'Маршрут поездки', 'Travel itinerary'))?></legend>
        <div class="form-grid">
          <label><?=Security::e($t('کشور مقصد', 'Страна назначения', 'Destination country'))?><input name="destination" maxlength="100" required></label>
          <label><?=Security::e($t('نوع ویزا', 'Тип визы', 'Visa type'))?><select name="visa_type" required><option value="" selected disabled><?=Security::e($t('انتخاب کنید', 'Выберите', 'Select'))?></option><option value="tourism"><?=Security::e($t('گردشگری', 'Туризм', 'Tourism'))?></option><option value="business"><?=Security::e($t('تجاری', 'Деловая', 'Business'))?></option><option value="transit"><?=Security::e($t('ترانزیت', 'Транзит', 'Transit'))?></option><option value="private"><?=Security::e($t('خصوصی', 'Частная', 'Private'))?></option><option value="study"><?=Security::e($t('تحصیلی', 'Учёба', 'Study'))?></option><option value="other"><?=Security::e($t('سایر', 'Другое', 'Other'))?></option></select></label>
          <label><?=Security::e($t('تعداد ورود', 'Количество въездов', 'Entries'))?><select name="entry_count" required><option value="" selected disabled><?=Security::e($t('انتخاب کنید', 'Выберите', 'Select'))?></option><option value="single"><?=Security::e($t('یک‌بار', 'Однократная', 'Single'))?></option><option value="double"><?=Security::e($t('دوبار', 'Двукратная', 'Double'))?></option><option value="multiple"><?=Security::e($t('چندبار', 'Многократная', 'Multiple'))?></option></select></label>
          <label><?=Security::e($t('تاریخ ورود', 'Дата въезда', 'Arrival date'))?><input name="arrival_date" type="date" min="<?=Security::e($today)?>" required dir="ltr"></label>
          <label><?=Security::e($t('تاریخ خروج', 'Дата выезда', 'Departure date'))?><input name="departure_date" type="date" min="<?=Security::e($today)?>" required dir="ltr"></label>
          <label><?=Security::e($t('نام محل اقامت', 'Название места проживания', 'Accommodation name'))?><input name="accommodation_name" maxlength="190" required></label>
          <label class="wide"><?=Security::e($t('نشانی محل اقامت در مقصد', 'Адрес проживания в стране назначения', 'Accommodation address in destination'))?><textarea name="accommodation_address" maxlength="500" rows="3" required></textarea></label>
        </div>
      </fieldset>

      <fieldset class="wide">
        <legend><?=Security::e($t('کار، هدف سفر و تماس اضطراری', 'Работа, цель поездки и экстренный контакт', 'Work, travel purpose, and emergency contact'))?></legend>
        <div class="form-grid">
          <label><?=Security::e($t('وضعیت شغلی', 'Статус занятости', 'Employment status'))?><select name="employment_status" required><option value="" selected disabled><?=Security::e($t('انتخاب کنید', 'Выберите', 'Select'))?></option><option value="employed"><?=Security::e($t('شاغل', 'Работаю по найму', 'Employed'))?></option><option value="self_employed"><?=Security::e($t('خویش‌فرما', 'Самозанятый', 'Self-employed'))?></option><option value="student"><?=Security::e($t('دانشجو', 'Студент', 'Student'))?></option><option value="retired"><?=Security::e($t('بازنشسته', 'Пенсионер', 'Retired'))?></option><option value="unemployed"><?=Security::e($t('بدون شغل', 'Не работаю', 'Unemployed'))?></option></select></label>
          <label><?=Security::e($t('نام محل کار یا تحصیل', 'Место работы или учёбы', 'Employer or school'))?><input name="employer_name" maxlength="190"></label>
          <label class="wide"><?=Security::e($t('نشانی محل کار یا تحصیل', 'Адрес места работы или учёбы', 'Employer or school address'))?><textarea name="employer_address" maxlength="500" rows="2"></textarea></label>
          <label class="wide"><?=Security::e($t('هدف و برنامه سفر', 'Цель и план поездки', 'Purpose and plan of travel'))?><textarea name="travel_purpose" maxlength="1000" rows="4" required></textarea></label>
          <label><?=Security::e($t('سابقه دریافت ویزا یا سفر به مقصد', 'Были ли ранее виза или поездки в страну назначения', 'Previous visa or travel to destination'))?><select name="previous_visa" required><option value="" selected disabled><?=Security::e($t('انتخاب کنید', 'Выберите', 'Select'))?></option><option value="no"><?=Security::e($t('خیر', 'Нет', 'No'))?></option><option value="yes"><?=Security::e($t('بله', 'Да', 'Yes'))?></option></select></label>
          <label><?=Security::e($t('توضیح سابقه در صورت وجود', 'Подробности при наличии', 'Details if applicable'))?><input name="previous_visa_details" maxlength="1000"></label>
          <label><?=Security::e($t('نام تماس اضطراری', 'Контактное лицо на случай ЧС', 'Emergency contact name'))?><input name="emergency_contact_name" maxlength="190" required></label>
          <label><?=Security::e($t('نسبت', 'Степень родства', 'Relationship'))?><input name="emergency_contact_relation" maxlength="100" required></label>
          <label><?=Security::e($t('شماره تماس اضطراری', 'Телефон экстренного контакта', 'Emergency contact phone'))?><input name="emergency_contact_phone" type="tel" maxlength="64" required inputmode="tel" dir="ltr"></label>
          <label class="wide"><?=Security::e($t('توضیحات تکمیلی', 'Дополнительная информация', 'Additional information'))?><textarea name="additional_information" maxlength="1000" rows="3"></textarea></label>
        </div>
      </fieldset>

      <div class="checkout-actions wide">
        <label class="consent"><input name="accuracy_consent" value="1" type="checkbox" required><span><?=Security::e($t('تأیید می‌کنم اطلاعات واردشده صحیح و کامل است.', 'Подтверждаю, что указанные данные верны и полны.', 'I confirm the information entered is accurate and complete.'))?></span></label>
        <label class="consent"><input name="privacy_consent" value="1" type="checkbox" required><span><?=Security::e($t('با رمزگذاری و استفاده از این اطلاعات فقط برای بررسی پرونده ویزا موافقم.', 'Согласен на шифрование и использование этих данных только для обработки визового дела.', 'I consent to encrypting and using this information only for processing this visa case.'))?></span></label>
        <button class="primary" type="submit"><?=Security::e($t('ثبت امن فرم eVisa', 'Безопасно отправить анкету eVisa', 'Securely submit eVisa form'))?> <span aria-hidden="true">←</span></button>
        <small><?=Security::e($t('به‌دلیل حفظ حریم خصوصی، پس از ثبت امکان مشاهده یا بازیابی شماره گذرنامه از این صفحه وجود ندارد.', 'Для защиты конфиденциальности после отправки номер паспорта нельзя просмотреть или восстановить с этой страницы.', 'For privacy protection, passport numbers cannot be viewed or recovered from this page after submission.'))?></small>
      </div>
    </form>
  <?php endif; ?>
</section>
