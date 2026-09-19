<?php
declare(strict_types=1);

namespace VazinCMS;

final class TelegramAssistantLocale
{
    private const SUPPORTED = ['ru', 'en', 'fa'];

    /** @return array<string,string> */
    public static function all(?string $locale): array
    {
        return self::catalog(self::normalize($locale));
    }

    /** @return array<string,string> */
    public static function messages(?string $locale): array
    {
        return self::all($locale);
    }

    public static function t(string $key, ?string $locale = null, array $values = []): string
    {
        $language = self::normalize($locale);
        $catalog = self::catalog($language);
        $fallback = self::catalog('fa');
        $message = (string)($catalog[$key] ?? $fallback[$key] ?? $key);
        foreach ($values as $name => $value) {
            $message = str_replace('{' . $name . '}', (string)$value, $message);
        }
        return $message;
    }

    public static function normalize(?string $locale): string
    {
        $value = strtolower(trim((string)$locale));
        $value = str_replace('_', '-', $value);
        $value = explode('-', $value, 2)[0];
        return in_array($value, self::SUPPORTED, true) ? $value : 'fa';
    }

    public static function resolve(?string $locale): string
    {
        return self::normalize($locale);
    }

    public static function direction(?string $locale): string
    {
        return self::normalize($locale) === 'fa' ? 'rtl' : 'ltr';
    }

    /** @return array<string,string> */
    private static function catalog(string $locale): array
    {
        $catalogs = [
            'ru' => [
                'app_title'=>'Помощник Оксаны', 'assistant'=>'Помощник', 'preview'=>'Предпросмотр',
                'preview_notice'=>'Это безопасный предпросмотр. Ничего не отправляется и не бронируется.',
                'loading'=>'Загрузка…', 'retry'=>'Повторить', 'close'=>'Закрыть', 'back'=>'Назад', 'continue'=>'Продолжить',
                'connection_required'=>'Откройте эту страницу из Telegram, чтобы продолжить.',
                'offline'=>'Нет соединения. Проверьте интернет и попробуйте снова.',
                'unexpected_error'=>'Не удалось выполнить действие. Попробуйте ещё раз.',
                'nav_home'=>'Главная', 'nav_services'=>'Услуги', 'nav_bookings'=>'Мои записи',
                'hello'=>'Здравствуйте, {name}', 'hello_guest'=>'Здравствуйте',
                'welcome_lead'=>'Я помогу выбрать формат, найти свободное время и передать вопрос Оксане.',
                'quick_book'=>'Записаться', 'quick_book_desc'=>'Выбрать услугу, день и удобное время',
                'quick_services'=>'Посмотреть услуги', 'quick_services_desc'=>'Форматы работы, длительность и стоимость',
                'quick_question'=>'Задать вопрос', 'quick_question_desc'=>'Продолжить разговор в Telegram',
                'quick_oksana'=>'Передать Оксане', 'quick_oksana_desc'=>'Попросить Оксану подключиться к разговору',
                'open_chat'=>'Открыть чат', 'request_handoff'=>'Позвать Оксану', 'handoff_sent'=>'Оксана увидит ваш вопрос в своём списке диалогов.',
                'services_title'=>'Услуги', 'services_lead'=>'Выберите формат, который ближе к вашей задаче.',
                'no_services'=>'Сейчас нет доступных услуг.', 'duration'=>'{minutes} мин', 'online'=>'Онлайн',
                'offline_mode'=>'Лично', 'hybrid'=>'Онлайн или лично', 'price_on_request'=>'Стоимость уточняется',
                'book_this'=>'Выбрать',
                'booking_title'=>'Запись', 'step_of'=>'Шаг {current} из {total}',
                'step_service'=>'Услуга', 'step_slot'=>'Время', 'step_details'=>'Контакты', 'step_confirm'=>'Проверка',
                'choose_service'=>'Что вы хотите выбрать?', 'choose_service_help'=>'Формат можно уточнить с Оксаной позже.',
                'choose_date'=>'Выберите день', 'choose_time'=>'Свободное время', 'timezone'=>'Часовой пояс: {timezone}',
                'no_slots'=>'На этот день свободного времени нет.', 'load_more_dates'=>'Другой день',
                'your_details'=>'Как к вам обращаться?', 'name'=>'Имя', 'phone'=>'Телефон', 'phone_optional'=>'Телефон — необязательно', 'email'=>'Эл. почта',
                'email_optional'=>'Эл. почта — необязательно', 'notes'=>'Короткий комментарий — необязательно',
                'notes_hint'=>'Например: онлайн или личная встреча. Медицинские сведения здесь не нужны.',
                'privacy_note'=>'Мы узнаём вас по Telegram. Дополнительные контакты можно оставить по желанию.',
                'review_title'=>'Проверьте запись', 'service'=>'Услуга', 'date_time'=>'Дата и время', 'contact'=>'Контакт',
                'edit'=>'Изменить', 'confirm_booking'=>'Подтвердить запись', 'holding'=>'Сохраняем выбранное время…',
                'booking_success'=>'Запись создана', 'booking_success_text'=>'Оксана получила информацию. Подтверждение также появится в Telegram.',
                'booking_reference'=>'Номер записи: {reference}', 'done'=>'Готово',
                'my_bookings_title'=>'Мои записи', 'my_bookings_lead'=>'Предстоящие и предыдущие записи, сделанные через помощника.',
                'no_bookings'=>'У вас пока нет записей.', 'new_booking'=>'Новая запись', 'cancel_booking'=>'Отменить запись',
                'cancel_confirm'=>'Отменить эту запись?', 'cancelled'=>'Запись отменена.',
                'status_held'=>'Время удерживается', 'status_pending'=>'Ожидает подтверждения', 'status_requested'=>'Запрос отправлен', 'status_confirmed'=>'Подтверждено',
                'status_cancelled'=>'Отменено', 'status_completed'=>'Завершено', 'status_expired'=>'Время освобождено', 'status_no_show'=>'Не состоялось',
                'field_required'=>'Заполните обязательное поле.', 'invalid_phone'=>'Проверьте номер телефона.',
                'invalid_email'=>'Проверьте адрес электронной почты.', 'slot_taken'=>'Это время уже занято. Выберите другое.',
                'session_expired'=>'Сеанс истёк. Откройте помощника из Telegram ещё раз.',
                'admin_title'=>'Рабочее место Оксаны', 'admin_inbox'=>'Диалоги', 'admin_reservations'=>'Записи',
                'admin_services'=>'Услуги', 'admin_slots'=>'Расписание', 'admin_settings'=>'Настройки',
                'inbox_empty'=>'Новых диалогов нет.', 'unread'=>'Новых: {count}', 'waiting_human'=>'Ждёт Оксану',
                'automated'=>'Отвечает помощник', 'human'=>'Отвечает Оксана', 'conversation_closed'=>'Диалог закрыт',
                'take_over'=>'Взять диалог', 'return_to_assistant'=>'Вернуть помощнику', 'reply'=>'Ответить',
                'reply_placeholder'=>'Напишите ответ…', 'send'=>'Отправить', 'mark_closed'=>'Завершить диалог',
                'admin_no_reservations'=>'Записей пока нет.', 'today'=>'Сегодня', 'upcoming'=>'Предстоящие', 'all'=>'Все',
                'confirm'=>'Подтвердить', 'complete'=>'Завершить', 'cancel'=>'Отменить',
                'service_name'=>'Название', 'service_summary'=>'Краткое описание', 'duration_minutes'=>'Длительность, мин',
                'price'=>'Стоимость', 'currency'=>'Валюта', 'enabled'=>'Доступно', 'save'=>'Сохранить', 'add_service'=>'Добавить услугу',
                'add_slot'=>'Добавить время', 'slot_start'=>'Начало', 'slot_end'=>'Окончание', 'block_slot'=>'Закрыть время',
                'slot_status_available'=>'Свободно', 'slot_status_open'=>'Свободно', 'slot_status_held'=>'Временно удерживается',
                'slot_status_booked'=>'Забронировано', 'slot_status_blocked'=>'Закрыто',
                'settings_name'=>'Имя помощника', 'settings_welcome'=>'Приветствие', 'settings_timezone'=>'Часовой пояс',
                'settings_profile_enabled'=>'Включить локальный профиль помощника',
                'settings_auto_reply'=>'Автоответы на организационные вопросы', 'settings_handoff'=>'Передавать неизвестные вопросы Оксане',
                'mini_app_url_title'=>'Ссылка клиентского Mini App', 'mini_app_url_help'=>'Укажите эту точную ссылку в BotFather. Ссылка управления /telegram/webapp остаётся отдельной.',
                'mini_app_url_label'=>'URL для BotFather', 'copy_url'=>'Копировать ссылку', 'copied'=>'Ссылка скопирована.',
                'business_connections_title'=>'Подключения аккаунта', 'business_connections_help'=>'Новое подключение остаётся выключенным, пока владелец или администратор CMS не проверит замаскированный ID и права.',
                'no_business_connections'=>'Ожидающих или проверенных подключений пока нет.', 'business_connection_ref'=>'Подключение {reference}',
                'business_owner'=>'Владелец', 'business_status_pending'=>'Ожидает проверки', 'business_status_authorized'=>'Одобрено', 'business_status_rejected'=>'Отклонено',
                'business_telegram_enabled'=>'Активно в Telegram', 'business_telegram_disabled'=>'Выключено в Telegram',
                'business_can_reply'=>'Ответы разрешены', 'business_cannot_reply'=>'Нет права отвечать', 'business_owner_mismatch'=>'ID не совпадает с закреплённым владельцем',
                'approve_connection'=>'Одобрить', 'reject_connection'=>'Отклонить',
                'admin_notifications'=>'Уведомления', 'admin_notifications_empty'=>'Всё в порядке. Новых уведомлений нет.',
                'notice_pending_reservations'=>'Есть заявки, ожидающие решения.', 'notice_waiting_handoffs'=>'Клиенты ждут ответа Оксаны.',
                'notice_no_slots'=>'На ближайшие 14 дней нет свободного времени.', 'notice_business_connection'=>'Telegram Business требует проверки.',
                'weekly_schedule_title'=>'Еженедельное расписание', 'weekly_schedule_help'=>'Укажите рабочие интервалы для выбранной услуги. Изменения применяются только к новым создаваемым временам.',
                'add_time_window'=>'Добавить интервал', 'remove_time_window'=>'Удалить', 'no_schedule_rules'=>'Рабочие интервалы ещё не заданы.', 'weekday'=>'День недели', 'start_time'=>'Начало', 'end_time'=>'Окончание',
                'slot_interval'=>'Шаг времени, мин', 'effective_from'=>'Действует с', 'effective_until'=>'Действует до', 'save_schedule'=>'Сохранить расписание', 'schedule_saved'=>'Расписание сохранено.',
                'generate_slots_title'=>'Создать время для записи', 'generate_slots_help'=>'Создание добавляет новые интервалы и не удаляет существующие или забронированные.',
                'generate_slots'=>'Создать время', 'generate_slots_confirm'=>'Создать время для выбранного периода?', 'slots_generated'=>'Создано: {created} · уже было: {existing} · пропущено: {skipped}',
                'from_date'=>'С даты', 'until_date'=>'До даты', 'reject_reservation'=>'Отклонить заявку', 'reject_reservation_confirm'=>'Отклонить эту заявку? Время снова станет доступно.',
                'reservation_rejected'=>'Заявка отклонена.', 'status_rejected'=>'Отклонено',
                'weekday_monday'=>'Понедельник', 'weekday_tuesday'=>'Вторник', 'weekday_wednesday'=>'Среда', 'weekday_thursday'=>'Четверг',
                'weekday_friday'=>'Пятница', 'weekday_saturday'=>'Суббота', 'weekday_sunday'=>'Воскресенье',
                'saved'=>'Изменения сохранены.', 'read_only'=>'Только просмотр', 'refresh'=>'Обновить',
            ],
            'en' => [
                'app_title'=>"Oksana's assistant", 'assistant'=>'Assistant', 'preview'=>'Preview',
                'preview_notice'=>'This is a safe preview. Nothing is sent or booked.',
                'loading'=>'Loading…', 'retry'=>'Try again', 'close'=>'Close', 'back'=>'Back', 'continue'=>'Continue',
                'connection_required'=>'Open this page from Telegram to continue.',
                'offline'=>'You appear to be offline. Check your connection and try again.',
                'unexpected_error'=>'The action could not be completed. Please try again.',
                'nav_home'=>'Home', 'nav_services'=>'Services', 'nav_bookings'=>'My bookings',
                'hello'=>'Hello, {name}', 'hello_guest'=>'Hello',
                'welcome_lead'=>'I can help you choose a format, find a time and pass a question to Oksana.',
                'quick_book'=>'Book a time', 'quick_book_desc'=>'Choose a service, day and convenient time',
                'quick_services'=>'View services', 'quick_services_desc'=>'Formats, duration and prices',
                'quick_question'=>'Ask a question', 'quick_question_desc'=>'Continue the conversation in Telegram',
                'quick_oksana'=>'Ask for Oksana', 'quick_oksana_desc'=>'Invite Oksana to join the conversation',
                'open_chat'=>'Open chat', 'request_handoff'=>'Ask Oksana to join', 'handoff_sent'=>'Oksana will see your question in her conversation list.',
                'services_title'=>'Services', 'services_lead'=>'Choose the format that feels closest to your goal.',
                'no_services'=>'No services are available right now.', 'duration'=>'{minutes} min', 'online'=>'Online',
                'offline_mode'=>'In person', 'hybrid'=>'Online or in person', 'price_on_request'=>'Price on request',
                'book_this'=>'Choose',
                'booking_title'=>'Booking', 'step_of'=>'Step {current} of {total}',
                'step_service'=>'Service', 'step_slot'=>'Time', 'step_details'=>'Contact', 'step_confirm'=>'Review',
                'choose_service'=>'What would you like to book?', 'choose_service_help'=>'You can clarify the format with Oksana later.',
                'choose_date'=>'Choose a day', 'choose_time'=>'Available times', 'timezone'=>'Time zone: {timezone}',
                'no_slots'=>'There are no available times on this day.', 'load_more_dates'=>'Choose another day',
                'your_details'=>'What should we call you?', 'name'=>'Name', 'phone'=>'Phone', 'phone_optional'=>'Phone — optional', 'email'=>'Email',
                'email_optional'=>'Email — optional', 'notes'=>'Short note — optional',
                'notes_hint'=>'For example: online or in person. No medical details are needed here.',
                'privacy_note'=>'Telegram identifies your booking. Additional contact details are optional.',
                'review_title'=>'Review your booking', 'service'=>'Service', 'date_time'=>'Date and time', 'contact'=>'Contact',
                'edit'=>'Edit', 'confirm_booking'=>'Confirm booking', 'holding'=>'Saving your selected time…',
                'booking_success'=>'Booking created', 'booking_success_text'=>'Oksana has received the details. Confirmation will also appear in Telegram.',
                'booking_reference'=>'Booking reference: {reference}', 'done'=>'Done',
                'my_bookings_title'=>'My bookings', 'my_bookings_lead'=>'Upcoming and previous bookings made through the assistant.',
                'no_bookings'=>'You do not have any bookings yet.', 'new_booking'=>'New booking', 'cancel_booking'=>'Cancel booking',
                'cancel_confirm'=>'Cancel this booking?', 'cancelled'=>'The booking was cancelled.',
                'status_held'=>'Time held', 'status_pending'=>'Awaiting confirmation', 'status_requested'=>'Request sent', 'status_confirmed'=>'Confirmed',
                'status_cancelled'=>'Cancelled', 'status_completed'=>'Completed', 'status_expired'=>'Hold expired', 'status_no_show'=>'Did not take place',
                'field_required'=>'Complete the required field.', 'invalid_phone'=>'Check the phone number.',
                'invalid_email'=>'Check the email address.', 'slot_taken'=>'That time has just been taken. Choose another.',
                'session_expired'=>'Your session expired. Open the assistant from Telegram again.',
                'admin_title'=>"Oksana's workspace", 'admin_inbox'=>'Inbox', 'admin_reservations'=>'Bookings',
                'admin_services'=>'Services', 'admin_slots'=>'Availability', 'admin_settings'=>'Settings',
                'inbox_empty'=>'There are no new conversations.', 'unread'=>'New: {count}', 'waiting_human'=>'Waiting for Oksana',
                'automated'=>'Assistant is replying', 'human'=>'Oksana is replying', 'conversation_closed'=>'Conversation closed',
                'take_over'=>'Take over', 'return_to_assistant'=>'Return to assistant', 'reply'=>'Reply',
                'reply_placeholder'=>'Write a reply…', 'send'=>'Send', 'mark_closed'=>'Close conversation',
                'admin_no_reservations'=>'There are no bookings yet.', 'today'=>'Today', 'upcoming'=>'Upcoming', 'all'=>'All',
                'confirm'=>'Confirm', 'complete'=>'Complete', 'cancel'=>'Cancel',
                'service_name'=>'Name', 'service_summary'=>'Short description', 'duration_minutes'=>'Duration, min',
                'price'=>'Price', 'currency'=>'Currency', 'enabled'=>'Available', 'save'=>'Save', 'add_service'=>'Add service',
                'add_slot'=>'Add time', 'slot_start'=>'Starts', 'slot_end'=>'Ends', 'block_slot'=>'Block time',
                'slot_status_available'=>'Available', 'slot_status_open'=>'Available', 'slot_status_held'=>'Temporarily held',
                'slot_status_booked'=>'Booked', 'slot_status_blocked'=>'Blocked',
                'settings_name'=>'Assistant name', 'settings_welcome'=>'Welcome message', 'settings_timezone'=>'Time zone',
                'settings_profile_enabled'=>'Enable the local assistant profile',
                'settings_auto_reply'=>'Auto-reply to organisational questions', 'settings_handoff'=>'Pass unknown questions to Oksana',
                'mini_app_url_title'=>'Customer Mini App link', 'mini_app_url_help'=>'Use this exact URL in BotFather. The /telegram/webapp management link remains separate.',
                'mini_app_url_label'=>'BotFather URL', 'copy_url'=>'Copy link', 'copied'=>'Link copied.',
                'business_connections_title'=>'Business account connections', 'business_connections_help'=>'A new connection stays disabled until a CMS owner or admin reviews its masked ID and permissions.',
                'no_business_connections'=>'There are no pending or reviewed connections yet.', 'business_connection_ref'=>'Connection {reference}',
                'business_owner'=>'Owner', 'business_status_pending'=>'Awaiting review', 'business_status_authorized'=>'Approved', 'business_status_rejected'=>'Rejected',
                'business_telegram_enabled'=>'Enabled in Telegram', 'business_telegram_disabled'=>'Disabled in Telegram',
                'business_can_reply'=>'Reply permission granted', 'business_cannot_reply'=>'Reply permission missing', 'business_owner_mismatch'=>'ID does not match the pinned owner',
                'approve_connection'=>'Approve', 'reject_connection'=>'Reject',
                'admin_notifications'=>'Notifications', 'admin_notifications_empty'=>'Everything is up to date. There are no new notifications.',
                'notice_pending_reservations'=>'Bookings are waiting for a decision.', 'notice_waiting_handoffs'=>'Customers are waiting for Oksana.',
                'notice_no_slots'=>'There are no available times in the next 14 days.', 'notice_business_connection'=>'A Telegram Business connection needs review.',
                'weekly_schedule_title'=>'Weekly schedule', 'weekly_schedule_help'=>'Set working windows for the selected service. Changes apply only to newly generated times.',
                'add_time_window'=>'Add window', 'remove_time_window'=>'Remove', 'no_schedule_rules'=>'No working windows have been set yet.', 'weekday'=>'Weekday', 'start_time'=>'Starts', 'end_time'=>'Ends',
                'slot_interval'=>'Time step, min', 'effective_from'=>'Effective from', 'effective_until'=>'Effective until', 'save_schedule'=>'Save schedule', 'schedule_saved'=>'Schedule saved.',
                'generate_slots_title'=>'Generate booking times', 'generate_slots_help'=>'Generation only adds times; it never deletes existing or booked slots.',
                'generate_slots'=>'Generate times', 'generate_slots_confirm'=>'Generate times for the selected period?', 'slots_generated'=>'Created: {created} · existing: {existing} · skipped: {skipped}',
                'from_date'=>'From date', 'until_date'=>'Until date', 'reject_reservation'=>'Reject request', 'reject_reservation_confirm'=>'Reject this request? The time will become available again.',
                'reservation_rejected'=>'The request was rejected.', 'status_rejected'=>'Rejected',
                'weekday_monday'=>'Monday', 'weekday_tuesday'=>'Tuesday', 'weekday_wednesday'=>'Wednesday', 'weekday_thursday'=>'Thursday',
                'weekday_friday'=>'Friday', 'weekday_saturday'=>'Saturday', 'weekday_sunday'=>'Sunday',
                'saved'=>'Changes saved.', 'read_only'=>'Read only', 'refresh'=>'Refresh',
            ],
            'fa' => [
                'app_title'=>'دستیار اوکسانا', 'assistant'=>'دستیار', 'preview'=>'پیش‌نمایش',
                'preview_notice'=>'این یک پیش‌نمایش امن است؛ هیچ پیام یا رزروی ارسال نمی‌شود.',
                'loading'=>'در حال بارگذاری…', 'retry'=>'تلاش دوباره', 'close'=>'بستن', 'back'=>'بازگشت', 'continue'=>'ادامه',
                'connection_required'=>'برای ادامه این صفحه را از داخل تلگرام باز کنید.',
                'offline'=>'اتصال اینترنت برقرار نیست. دوباره تلاش کنید.', 'unexpected_error'=>'انجام عملیات ممکن نشد. دوباره تلاش کنید.',
                'nav_home'=>'خانه', 'nav_services'=>'خدمات', 'nav_bookings'=>'رزروهای من',
                'hello'=>'سلام {name}', 'hello_guest'=>'سلام',
                'welcome_lead'=>'برای انتخاب خدمت، پیدا کردن زمان مناسب و رساندن سؤال به اوکسانا کنارتان هستم.',
                'quick_book'=>'رزرو وقت', 'quick_book_desc'=>'انتخاب خدمت، روز و ساعت مناسب',
                'quick_services'=>'مشاهده خدمات', 'quick_services_desc'=>'نوع خدمات، مدت و هزینه',
                'quick_question'=>'پرسیدن سؤال', 'quick_question_desc'=>'ادامه گفتگو در تلگرام',
                'quick_oksana'=>'ارتباط با اوکسانا', 'quick_oksana_desc'=>'درخواست حضور اوکسانا در گفتگو',
                'open_chat'=>'بازکردن گفتگو', 'request_handoff'=>'دعوت از اوکسانا', 'handoff_sent'=>'اوکسانا سؤال شما را در فهرست گفتگوها خواهد دید.',
                'services_title'=>'خدمات', 'services_lead'=>'خدمتی را انتخاب کنید که به خواسته شما نزدیک‌تر است.',
                'no_services'=>'در حال حاضر خدمتی در دسترس نیست.', 'duration'=>'{minutes} دقیقه', 'online'=>'آنلاین',
                'offline_mode'=>'حضوری', 'hybrid'=>'آنلاین یا حضوری', 'price_on_request'=>'هزینه با هماهنگی', 'book_this'=>'انتخاب',
                'booking_title'=>'رزرو', 'step_of'=>'مرحله {current} از {total}', 'step_service'=>'خدمت', 'step_slot'=>'زمان',
                'step_details'=>'اطلاعات تماس', 'step_confirm'=>'بازبینی', 'choose_service'=>'کدام خدمت را می‌خواهید؟',
                'choose_service_help'=>'جزئیات را بعداً می‌توانید با اوکسانا هماهنگ کنید.', 'choose_date'=>'روز را انتخاب کنید',
                'choose_time'=>'زمان‌های آزاد', 'timezone'=>'منطقه زمانی: {timezone}', 'no_slots'=>'برای این روز زمان آزادی وجود ندارد.',
                'load_more_dates'=>'روز دیگری', 'your_details'=>'چطور صدایتان کنیم؟', 'name'=>'نام', 'phone'=>'تلفن', 'phone_optional'=>'تلفن — اختیاری',
                'email'=>'ایمیل', 'email_optional'=>'ایمیل — اختیاری', 'notes'=>'توضیح کوتاه — اختیاری',
                'notes_hint'=>'مثلاً آنلاین یا حضوری. اینجا نیازی به نوشتن اطلاعات پزشکی نیست.',
                'privacy_note'=>'رزرو با هویت تلگرام شما پیگیری می‌شود؛ اطلاعات تماس اضافی اختیاری است.', 'review_title'=>'رزرو را بررسی کنید',
                'service'=>'خدمت', 'date_time'=>'تاریخ و زمان', 'contact'=>'اطلاعات تماس', 'edit'=>'ویرایش',
                'confirm_booking'=>'تأیید رزرو', 'holding'=>'در حال نگهداری زمان انتخابی…', 'booking_success'=>'رزرو ثبت شد',
                'booking_success_text'=>'اطلاعات به اوکسانا رسید و تأیید در تلگرام نیز نمایش داده می‌شود.',
                'booking_reference'=>'شماره رزرو: {reference}', 'done'=>'تمام', 'my_bookings_title'=>'رزروهای من',
                'my_bookings_lead'=>'رزروهای آینده و گذشته ثبت‌شده با دستیار.', 'no_bookings'=>'هنوز رزروی ندارید.',
                'new_booking'=>'رزرو جدید', 'cancel_booking'=>'لغو رزرو', 'cancel_confirm'=>'این رزرو لغو شود؟', 'cancelled'=>'رزرو لغو شد.',
                'status_held'=>'زمان موقتاً نگه داشته شد', 'status_pending'=>'در انتظار تأیید', 'status_requested'=>'درخواست ارسال شد', 'status_confirmed'=>'تأییدشده',
                'status_cancelled'=>'لغوشده', 'status_completed'=>'انجام‌شده', 'status_expired'=>'زمان آزاد شد', 'status_no_show'=>'انجام‌نشده',
                'field_required'=>'این قسمت را کامل کنید.', 'invalid_phone'=>'شماره تلفن را بررسی کنید.',
                'invalid_email'=>'ایمیل را بررسی کنید.', 'slot_taken'=>'این زمان رزرو شد؛ زمان دیگری انتخاب کنید.',
                'session_expired'=>'نشست منقضی شد؛ دستیار را دوباره از تلگرام باز کنید.',
                'admin_title'=>'محیط کار اوکسانا', 'admin_inbox'=>'گفتگوها', 'admin_reservations'=>'رزروها',
                'admin_services'=>'خدمات', 'admin_slots'=>'زمان‌های آزاد', 'admin_settings'=>'تنظیمات',
                'inbox_empty'=>'گفتگوی جدیدی وجود ندارد.', 'unread'=>'جدید: {count}', 'waiting_human'=>'منتظر اوکسانا',
                'automated'=>'دستیار پاسخ می‌دهد', 'human'=>'اوکسانا پاسخ می‌دهد', 'conversation_closed'=>'گفتگو بسته شده',
                'take_over'=>'در دست گرفتن گفتگو', 'return_to_assistant'=>'بازگرداندن به دستیار', 'reply'=>'پاسخ',
                'reply_placeholder'=>'پاسخ را بنویسید…', 'send'=>'ارسال', 'mark_closed'=>'پایان گفتگو',
                'admin_no_reservations'=>'هنوز رزروی ثبت نشده است.', 'today'=>'امروز', 'upcoming'=>'آینده', 'all'=>'همه',
                'confirm'=>'تأیید', 'complete'=>'تکمیل', 'cancel'=>'لغو', 'service_name'=>'نام',
                'service_summary'=>'توضیح کوتاه', 'duration_minutes'=>'مدت، دقیقه', 'price'=>'هزینه', 'currency'=>'واحد پول',
                'enabled'=>'فعال', 'save'=>'ذخیره', 'add_service'=>'افزودن خدمت', 'add_slot'=>'افزودن زمان',
                'slot_start'=>'شروع', 'slot_end'=>'پایان', 'block_slot'=>'بستن زمان', 'settings_name'=>'نام دستیار',
                'slot_status_available'=>'آزاد', 'slot_status_open'=>'آزاد', 'slot_status_held'=>'موقتاً نگه‌داری‌شده',
                'slot_status_booked'=>'رزروشده', 'slot_status_blocked'=>'بسته',
                'settings_welcome'=>'پیام خوشامد', 'settings_timezone'=>'منطقه زمانی',
                'settings_profile_enabled'=>'فعال‌کردن پروفایل محلی دستیار',
                'settings_auto_reply'=>'پاسخ خودکار به سؤال‌های اجرایی', 'settings_handoff'=>'ارجاع سؤال‌های ناشناخته به اوکسانا',
                'mini_app_url_title'=>'پیوند مینی‌اپ مشتری', 'mini_app_url_help'=>'همین نشانی دقیق را در BotFather ثبت کنید. پیوند مدیریتی /telegram/webapp جدا باقی می‌ماند.',
                'mini_app_url_label'=>'نشانی BotFather', 'copy_url'=>'کپی پیوند', 'copied'=>'پیوند کپی شد.',
                'business_connections_title'=>'اتصال‌های حساب Business', 'business_connections_help'=>'هر اتصال تازه تا زمان بررسی شناسهٔ پوشانده‌شده و مجوزها توسط مالک یا مدیر CMS غیرفعال می‌ماند.',
                'no_business_connections'=>'هنوز اتصال در انتظار یا بررسی‌شده‌ای وجود ندارد.', 'business_connection_ref'=>'اتصال {reference}',
                'business_owner'=>'مالک', 'business_status_pending'=>'در انتظار بررسی', 'business_status_authorized'=>'تأییدشده', 'business_status_rejected'=>'ردشده',
                'business_telegram_enabled'=>'فعال در تلگرام', 'business_telegram_disabled'=>'غیرفعال در تلگرام',
                'business_can_reply'=>'مجوز پاسخ‌گویی دارد', 'business_cannot_reply'=>'مجوز پاسخ‌گویی ندارد', 'business_owner_mismatch'=>'شناسه با مالک تثبیت‌شده یکسان نیست',
                'approve_connection'=>'تأیید', 'reject_connection'=>'رد',
                'admin_notifications'=>'اعلان‌های مدیریت', 'admin_notifications_empty'=>'همه‌چیز مرتب است؛ اعلان جدیدی وجود ندارد.',
                'notice_pending_reservations'=>'درخواست‌هایی منتظر تصمیم مدیر هستند.', 'notice_waiting_handoffs'=>'مشتریانی منتظر پاسخ اوکسانا هستند.',
                'notice_no_slots'=>'برای ۱۴ روز آینده زمان آزادی وجود ندارد.', 'notice_business_connection'=>'اتصال Telegram Business نیاز به بررسی دارد.',
                'weekly_schedule_title'=>'برنامه هفتگی', 'weekly_schedule_help'=>'بازه‌های کاری خدمت انتخاب‌شده را مشخص کنید. تغییرات فقط روی زمان‌های بعدی اثر دارند.',
                'add_time_window'=>'افزودن بازه', 'remove_time_window'=>'حذف', 'no_schedule_rules'=>'هنوز بازهٔ کاری ثبت نشده است.', 'weekday'=>'روز هفته', 'start_time'=>'شروع', 'end_time'=>'پایان',
                'slot_interval'=>'فاصله زمان‌ها، دقیقه', 'effective_from'=>'شروع اعتبار', 'effective_until'=>'پایان اعتبار', 'save_schedule'=>'ذخیره برنامه', 'schedule_saved'=>'برنامه هفتگی ذخیره شد.',
                'generate_slots_title'=>'تولید زمان‌های رزرو', 'generate_slots_help'=>'تولید فقط زمان تازه اضافه می‌کند و زمان موجود یا رزروشده را حذف نمی‌کند.',
                'generate_slots'=>'تولید زمان‌ها', 'generate_slots_confirm'=>'زمان‌های این بازه تولید شوند؟', 'slots_generated'=>'ساخته‌شده: {created} · موجود: {existing} · ردشده: {skipped}',
                'from_date'=>'از تاریخ', 'until_date'=>'تا تاریخ', 'reject_reservation'=>'رد درخواست', 'reject_reservation_confirm'=>'این درخواست رد شود؟ زمان دوباره آزاد خواهد شد.',
                'reservation_rejected'=>'درخواست رد شد.', 'status_rejected'=>'ردشده',
                'weekday_monday'=>'دوشنبه', 'weekday_tuesday'=>'سه‌شنبه', 'weekday_wednesday'=>'چهارشنبه', 'weekday_thursday'=>'پنج‌شنبه',
                'weekday_friday'=>'جمعه', 'weekday_saturday'=>'شنبه', 'weekday_sunday'=>'یکشنبه',
                'saved'=>'تغییرات ذخیره شد.', 'read_only'=>'فقط مشاهده', 'refresh'=>'به‌روزرسانی',
            ],
        ];

        return $catalogs[$locale] ?? $catalogs['fa'];
    }
}
