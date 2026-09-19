<?php
declare(strict_types=1);
namespace VazinCMS;

final class UiLocale
{
    private static string $locale='en';
    private static array $map=[];
    private static string $protectionNonce='';
    private static array $protected=[];
    private const MESSAGES=[
        'internal_error'=>['fa'=>'خطای داخلی رخ داد. شناسه پیگیری: {correlation}','en'=>'An internal error occurred. Reference: {correlation}','ru'=>'Произошла внутренняя ошибка. Код: {correlation}','ar'=>'حدث خطأ داخلي. رمز التتبع: {correlation}'],
        'pay_module_disabled'=>['fa'=>'ماژول پرداخت فعال نیست.','en'=>'The payment module is not enabled.','ru'=>'Платёжный модуль не включён.','ar'=>'وحدة الدفع غير مفعّلة.'],
        'pay_title'=>['fa'=>'وزین پی','en'=>'Vazin Pay','ru'=>'Vazin Pay','ar'=>'وزين باي'],
        'pay_login_title'=>['fa'=>'ورود به وزین پی','en'=>'Sign in to Vazin Pay','ru'=>'Вход в Vazin Pay','ar'=>'تسجيل الدخول إلى وزين باي'],
        'pay_login_failed_title'=>['fa'=>'ورود ناموفق','en'=>'Sign-in failed','ru'=>'Не удалось войти','ar'=>'فشل تسجيل الدخول'],
        'pay_login_failed'=>['fa'=>'ارتباط امن با وزین پی برقرار نشد.','en'=>'A secure connection to Vazin Pay could not be established.','ru'=>'Не удалось установить защищённое соединение с Vazin Pay.','ar'=>'تعذّر إنشاء اتصال آمن مع وزين باي.'],
        'pay_wallet_title'=>['fa'=>'کیف پول وزین پی','en'=>'Vazin Pay wallet','ru'=>'Кошелёк Vazin Pay','ar'=>'محفظة وزين باي'],
        'pay_transactions_title'=>['fa'=>'تراکنش‌های وزین پی','en'=>'Vazin Pay transactions','ru'=>'Транзакции Vazin Pay','ar'=>'معاملات وزين باي'],
        'visa_store_meta'=>['fa'=>'خدمات ویزا و مهاجرت روسیه','en'=>'Russia visa and migration services','ru'=>'Визы и миграционные услуги в России','ar'=>'خدمات التأشيرات والهجرة إلى روسيا'],
        'visa_request_meta'=>['fa'=>'ثبت سفارش ویزا','en'=>'Submit a visa order','ru'=>'Оформление визового заказа','ar'=>'تقديم طلب تأشيرة'],
        'visa_tracking_meta'=>['fa'=>'پیگیری سفارش','en'=>'Track an order','ru'=>'Отслеживание заказа','ar'=>'تتبّع الطلب'],
        'visa_meta_description'=>['fa'=>'ثبت و پیگیری امن درخواست سفر و ویزا','en'=>'Securely submit and track travel and visa requests','ru'=>'Безопасная подача и отслеживание заявок на поездки и визы','ar'=>'تقديم طلبات السفر والتأشيرات وتتبعها بأمان'],
        'visa_order_meta'=>['fa'=>'سفارش {id}','en'=>'Order {id}','ru'=>'Заказ {id}','ar'=>'الطلب {id}'],
        'visa_package_inactive'=>['fa'=>'بسته انتخاب‌شده فعال نیست.','en'=>'The selected package is not active.','ru'=>'Выбранный пакет неактивен.','ar'=>'الباقة المحددة غير مفعّلة.'],
        'visa_service_invalid'=>['fa'=>'نوع خدمت معتبر نیست.','en'=>'The service type is invalid.','ru'=>'Неверный тип услуги.','ar'=>'نوع الخدمة غير صالح.'],
        'visa_name_invalid'=>['fa'=>'نام کامل را صحیح وارد کنید.','en'=>'Enter a valid full name.','ru'=>'Укажите корректное полное имя.','ar'=>'أدخل الاسم الكامل بشكل صحيح.'],
        'visa_phone_invalid'=>['fa'=>'شماره تماس معتبر نیست.','en'=>'The phone number is invalid.','ru'=>'Неверный номер телефона.','ar'=>'رقم الهاتف غير صالح.'],
        'visa_email_invalid'=>['fa'=>'ایمیل معتبر نیست.','en'=>'The email address is invalid.','ru'=>'Неверный адрес электронной почты.','ar'=>'عنوان البريد الإلكتروني غير صالح.'],
        'visa_tracking_invalid'=>['fa'=>'شماره سفارش یا کد پیگیری صحیح نیست.','en'=>'The order number or tracking code is incorrect.','ru'=>'Неверный номер заказа или код отслеживания.','ar'=>'رقم الطلب أو رمز التتبع غير صحيح.'],
        'visa_gateway_invalid'=>['fa'=>'درگاه پرداخت پاسخ معتبر نداد؛ سفارش شما محفوظ است و با تازه‌سازی دوباره بررسی می‌شود.','en'=>'The payment service returned no valid response. Your order is safe and will be checked again after refresh.','ru'=>'Платёжный сервис не вернул корректный ответ. Заказ сохранён и будет проверен после обновления.','ar'=>'لم تُرجع خدمة الدفع استجابة صالحة. طلبك محفوظ وسيُفحص مجدداً بعد التحديث.'],
        'visa_pay_not_configured'=>['fa'=>'اتصال VazinPay هنوز توسط مدیر تکمیل نشده است.','en'=>'The VazinPay connection has not yet been completed by the administrator.','ru'=>'Администратор ещё не завершил подключение VazinPay.','ar'=>'لم يُكمل المسؤول اتصال VazinPay بعد.'],
        'payment_invalid'=>['fa'=>'پرداخت معتبر نیست.','en'=>'The payment request is invalid.','ru'=>'Недействительный платёжный запрос.','ar'=>'طلب الدفع غير صالح.'],
        'upload_file_invalid'=>['fa'=>'فایل معتبر دریافت نشد.','en'=>'No valid file was received.','ru'=>'Корректный файл не получен.','ar'=>'لم يتم استلام ملف صالح.'],
        'upload_file_too_large'=>['fa'=>'حداکثر حجم هر فایل ۱۵ مگابایت است.','en'=>'Each file may be at most 15 MB.','ru'=>'Максимальный размер файла — 15 МБ.','ar'=>'الحد الأقصى لحجم كل ملف هو 15 ميغابايت.'],
        'upload_type_invalid'=>['fa'=>'فقط PDF، JPG، PNG و WEBP مجاز است.','en'=>'Only PDF, JPG, PNG and WEBP files are allowed.','ru'=>'Разрешены только PDF, JPG, PNG и WEBP.','ar'=>'يُسمح فقط بملفات PDF وJPG وPNG وWEBP.'],
        'upload_storage_unavailable'=>['fa'=>'فضای نگهداری مدارک آماده نیست.','en'=>'Document storage is not available.','ru'=>'Хранилище документов недоступно.','ar'=>'مساحة تخزين المستندات غير متاحة.'],
        'upload_store_failed'=>['fa'=>'ذخیره فایل انجام نشد.','en'=>'The file could not be stored.','ru'=>'Не удалось сохранить файл.','ar'=>'تعذّر حفظ الملف.'],
        'upload_failed_generic'=>['fa'=>'بارگذاری امن فایل انجام نشد. دوباره تلاش کنید.','en'=>'The secure file upload failed. Please try again.','ru'=>'Не удалось безопасно загрузить файл. Повторите попытку.','ar'=>'فشل رفع الملف الآمن. حاول مرة أخرى.'],
        'document_uploaded'=>['fa'=>'مدرک جدید توسط متقاضی بارگذاری شد.','en'=>'The applicant uploaded a new document.','ru'=>'Заявитель загрузил новый документ.','ar'=>'رفع مقدم الطلب مستنداً جديداً.'],
        'document_accepted'=>['fa'=>'مدرک تأیید شد.','en'=>'The document was accepted.','ru'=>'Документ принят.','ar'=>'تم قبول المستند.'],
        'document_rejected'=>['fa'=>'مدرک نیازمند اصلاح است.','en'=>'The document needs correction.','ru'=>'Документ требует исправления.','ar'=>'يحتاج المستند إلى تصحيح.'],
    ];

    public static function supported():array
    {
        return ['fa'=>'فارسی','en'=>'English','ru'=>'Русский','ar'=>'العربية'];
    }

    /** Route locale wins for public localized routes. In the authenticated
     * admin UI, an explicit local preference wins, followed by the centrally
     * resolved Vazin ID locale, environment override, configured brand
     * default, browser, then English. */
    public static function detect(?string $route=null,?string $identity=null,?string $brandDefault=null):string
    {
        if(self::valid($route))return (string)$route;
        $query=strtolower((string)($_GET['ui_lang']??''));
        if(self::valid($query)){
            setcookie('vazincms_locale',$query,['expires'=>time()+31536000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
            return$query;
        }
        $cookie=strtolower((string)($_COOKIE['vazincms_locale']??''));
        if(self::valid($cookie))return$cookie;
        if(self::valid($identity))return (string)$identity;
        $brand=strtolower(trim((string)(getenv('DEFAULT_LOCALE')?:'')));
        if(self::valid($brand))return$brand;
        if(self::valid($brandDefault))return(string)$brandDefault;
        $header=strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE']??''));
        foreach(explode(',',$header)as$part){
            $candidate=substr(trim(explode(';',$part)[0]),0,2);
            if(self::valid($candidate))return$candidate;
        }
        return'en';
    }

    public static function boot(?string $route=null,?string $identity=null,?string $brandDefault=null):void
    {
        self::$locale=self::detect($route,$identity,$brandDefault);
        self::$protectionNonce=bin2hex(random_bytes(16));self::$protected=[];
        $file=dirname(__DIR__).'/languages/ui-'.self::$locale.'.php';
        self::$map=is_file($file)?(array)require$file:[];
        $publicFile=dirname(__DIR__).'/languages/ui-public-'.self::$locale.'.php';
        if(is_file($publicFile))self::$map=array_replace(self::$map,(array)require$publicFile);
        $common=[
            'en'=>['منو'=>'Menu','مدیریت'=>'Administration','صفحه‌ها'=>'Pages','فعال'=>'Active','غیرفعال'=>'Inactive'],
            'ru'=>['منو'=>'Меню','مدیریت'=>'Управление','صفحه‌ها'=>'Страницы','فعال'=>'Активно','غیرفعال'=>'Неактивно'],
            'ar'=>['منو'=>'القائمة','مدیریت'=>'الإدارة','صفحه‌ها'=>'الصفحات','فعال'=>'نشط','غیرفعال'=>'غير نشط'],
        ];
        self::$map=array_replace(self::$map,$common[self::$locale]??[]);
    }

    public static function locale():string{return self::$locale;}
    public static function direction():string{return in_array(self::$locale,['fa','ar'],true)?'rtl':'ltr';}
    public static function t(string $text):string{return self::$locale==='fa'?$text:(string)(self::$map[$text]??$text);}

    public static function message(string $key,array $values=[]):string
    {
        if(!isset(self::MESSAGES[$key]))throw new \InvalidArgumentException('unknown locale message key');
        $template=self::MESSAGES[$key][self::$locale]??self::MESSAGES[$key]['en'];
        foreach($values as$name=>$value)$template=str_replace('{'.$name.'}',(string)$value,$template);
        return$template;
    }

    public static function protect(string $rendered):string
    {
        $token='__VZ_DYNAMIC_'.self::$protectionNonce.'_'.count(self::$protected).'__';
        self::$protected[$token]=$rendered;return$token;
    }

    public static function html(string $html):string
    {
        if(self::$locale==='fa')return self::$protected?strtr($html,self::$protected):$html;
        $html=strtr($html,self::$map);
        // Inspect while dynamic slots are still opaque. Customer, profile and
        // locale-name values may legitimately use Persian/Arabic and must not
        // trigger or be modified by the static-copy audit.
        if(in_array(self::$locale,['en','ru'],true)){
            $visible=strip_tags((string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is','',$html));
            if(preg_match('/[\x{0600}-\x{06FF}]/u',$visible))error_log('[VazinCMS i18n] untranslated route='.($_SERVER['REQUEST_URI']??'/').' locale='.self::$locale);
        }
        if(self::$protected)$html=strtr($html,self::$protected);
        return$html;
    }

    private static function valid(?string $value):bool
    {
        return is_string($value)&&isset(self::supported()[$value]);
    }

}
