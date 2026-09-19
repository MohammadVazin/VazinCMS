<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use VazinCMS\Audit;
use VazinCMS\Database;
use VazinCMS\Security;
use VazinCMS\TravelAlertService;
use VazinCMS\UiLocale;
use VazinCMS\View;

/**
 * Opens a zero-price, operator-reviewed eVisa case.  This is deliberately
 * separate from consumer checkout: it creates no invoice, provider order or
 * supplier request, then hands the applicant to the protected full intake.
 */
final class ManualVisaCaseController
{
    private const LOCALES = ['fa', 'ar', 'en', 'ru', 'tr', 'hy', 'kk', 'tg', 'zh'];

    public function intake(string $locale): void
    {
        $locale = $this->locale($locale);
        UiLocale::boot($locale);
        $form = $this->form();
        $errors = [];
        $intakeCopy = $this->copy($locale);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                Security::verifyCsrf();
                $case = $this->createCase($locale, $this->validated($form));
                $_SESSION['travel_order_access'][$case['public_id']] = $case['access_code'];
                header('Location: /' . rawurlencode($locale) . '/order/' . rawurlencode($case['public_id']) . '?created=1');
                return;
            } catch (InvalidArgumentException $error) {
                $errors['_form'] = $error->getMessage();
            } catch (Throwable $error) {
                // Never log intake values or identifiers.
                error_log('[VazinCMS] manual eVisa case creation failed type=' . $error::class);
                $errors['_form'] = $intakeCopy['error'];
            }
        }

        $base = rtrim((string) getenv('APP_URL'), '/');
        View::renderPublic('visa-manual-intake', [
            'locale' => $locale,
            'intakeCopy' => $intakeCopy,
            'form' => $form,
            'errors' => $errors,
            'meta' => [
                'title' => $intakeCopy['meta_title'],
                'description' => $intakeCopy['meta_description'],
                'canonical' => $base === '' ? '' : $base . '/' . $locale . '/visa',
                'schema_type' => 'WebPage',
            ],
        ]);
    }

    /** @return array{full_name:string,email:string,phone:string,nationality:string,destination:string,travel_date:string,notes:string} */
    private function validated(array $form): array
    {
        $fullName = $this->text($form['full_name'], 'نام متقاضی', 3, 190);
        $phone = trim($form['phone']);
        $email = trim($form['email']);
        $nationality = $this->text($form['nationality'], 'تابعیت', 2, 100);
        $destination = $this->text($form['destination'], 'کشور مقصد', 2, 100);
        $notes = $this->text($form['notes'], 'توضیح', 0, 1000, false);
        $travelDate = trim($form['travel_date']);

        if (preg_match('/^[\p{N}+().\-\s]+$/u', $phone) !== 1 || mb_strlen($phone) < 6 || mb_strlen($phone) > 64) {
            throw new InvalidArgumentException('شماره تماس معتبر را وارد کنید.');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            throw new InvalidArgumentException('نشانی ایمیل معتبر نیست.');
        }
        if ($travelDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
            $warnings = DateTimeImmutable::getLastErrors();
            if (
                $date === false || $date->format('Y-m-d') !== $travelDate
                || (is_array($warnings) && ($warnings['warning_count'] > 0 || $warnings['error_count'] > 0))
                || $travelDate < gmdate('Y-m-d')
            ) {
                throw new InvalidArgumentException('تاریخ تقریبی سفر معتبر نیست.');
            }
        }
        if ((string) ($_POST['manual_review_consent'] ?? '') !== '1') {
            throw new InvalidArgumentException('تأیید بررسی دستی و استفادهٔ امن از اطلاعات تماس لازم است.');
        }

        return [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'nationality' => $nationality,
            'destination' => $destination,
            'travel_date' => $travelDate,
            'notes' => $notes,
        ];
    }

    /** @param array{full_name:string,email:string,phone:string,nationality:string,destination:string,travel_date:string,notes:string} $data
     *  @return array{public_id:string,access_code:string}
     */
    private function createCase(string $locale, array $data): array
    {
        $publicId = 'VZ' . strtoupper(bin2hex(random_bytes(6)));
        $accessCode = strtoupper(bin2hex(random_bytes(4)));
        $title = $this->copy($locale)['case_title'];
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'INSERT INTO travel_orders('
            . 'public_id,access_hash,locale,service_type,full_name,email,phone,destination,nationality,travelers,travel_date,notes,'
            . 'amount,currency,payment_status,product_code,product_title) '
            . 'VALUES(:public_id,:access_hash,:locale,\'visa\',:full_name,:email,:phone,:destination,:nationality,1,:travel_date,:notes,0,\'RUB\',\'not_required\',\'manual-evisa\',:product_title)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'access_hash' => hash('sha256', $accessCode),
            'locale' => $locale,
            'full_name' => $data['full_name'],
            'email' => $data['email'] !== '' ? $data['email'] : null,
            'phone' => $data['phone'],
            'destination' => $data['destination'],
            'nationality' => $data['nationality'],
            'travel_date' => $data['travel_date'] !== '' ? $data['travel_date'] : null,
            'notes' => $data['notes'] !== '' ? $data['notes'] : null,
            'product_title' => $title,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        if ($orderId < 1) {
            throw new \RuntimeException('شناسهٔ پرونده ساخته نشد.');
        }

        try {
            $order = $pdo->prepare('SELECT * FROM travel_orders WHERE id=:id LIMIT 1');
            $order->execute(['id' => $orderId]);
            $row = $order->fetch();
            if (is_array($row)) TravelAlertService::enqueueOrderEvent($row, 'visa_request_created');
        } catch (Throwable $error) {
            // Alert delivery is independently consent-gated and must never roll
            // back a committed case creation.
            error_log('[VazinCMS] manual eVisa alert enqueue failed type=' . $error::class);
        }
        try {
            Audit::log('visa.manual_case_created', 'پروندهٔ دستی eVisa بدون پرداخت آنلاین ایجاد شد', null, ['order_id' => $orderId]);
        } catch (Throwable $error) {
            error_log('[VazinCMS] manual eVisa audit failed type=' . $error::class);
        }

        return ['public_id' => $publicId, 'access_code' => $accessCode];
    }

    /** @return array{full_name:string,email:string,phone:string,nationality:string,destination:string,travel_date:string,notes:string} */
    private function form(): array
    {
        return [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'nationality' => trim((string) ($_POST['nationality'] ?? '')),
            'destination' => trim((string) ($_POST['destination'] ?? '')),
            'travel_date' => trim((string) ($_POST['travel_date'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
    }

    private function text(string $value, string $label, int $min, int $max, bool $required = true): string
    {
        $value = trim($value);
        if ($value === '' && !$required) return '';
        if ($value === '' || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException($label . ' معتبر نیست.');
        }
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException($label . ' باید بین ' . $min . ' تا ' . $max . ' نویسه باشد.');
        }
        return $value;
    }

    private function locale(string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'fa';
    }

    /** @return array<string,string> */
    private function copy(string $locale): array
    {
        if ($locale === 'ru') return [
            'eyebrow' => 'РУЧНОЙ ВИЗОВЫЙ КЕЙС',
            'title' => 'Откройте защищённое дело eVisa без онлайн-продажи.',
            'text' => 'Это рабочий пример white-label процесса: сначала создаётся дело без платежа и без обещания выдачи, затем заявитель заполняет полную защищённую анкету, а агент проверяет её вручную.',
            'form_title' => 'Минимум данных для открытия дела',
            'form_text' => 'Не вводите здесь паспортные, платёжные данные, пароли или ключи. Полная анкета откроется только после создания защищённого дела.',
            'name' => 'Имя заявителя', 'email' => 'Email (необязательно)', 'phone' => 'Телефон', 'nationality' => 'Гражданство', 'destination' => 'Страна назначения', 'date' => 'Ориентировочная дата поездки (необязательно)', 'notes' => 'Контекст для оператора (необязательно)',
            'consent' => 'Я понимаю, что это ручная проверка без автоматической выдачи, и согласен(на) на безопасное использование этих контактных данных для дела.',
            'submit' => 'Открыть ручное дело eVisa', 'side_title' => 'Что будет дальше', 'side_one' => 'Вы получите код доступа к делу — это не заказ и не счёт.', 'side_two' => 'Полная анкета запрашивает паспортные, контактные, поездочные и рабочие данные в защищённом шаге.', 'side_three' => 'Агент проверяет дело вручную; Telegram-уведомления доступны только по согласию.',
            'case_title' => 'Ручное дело eVisa', 'error' => 'Сейчас не удалось открыть дело. Повторите попытку позже.', 'meta_title' => 'Ручной eVisa-кейс | Vazin Visa', 'meta_description' => 'Защищённый white-label процесс ручного eVisa-кейса без онлайн-оплаты.',
        ];
        if ($locale === 'en') return [
            'eyebrow' => 'MANUAL EVISA CASE',
            'title' => 'Open a protected eVisa case without a consumer checkout.',
            'text' => 'This is a working white-label flow: it opens a no-payment case with no promise of issuance, then gives the applicant a full protected intake for the agency to review manually.',
            'form_title' => 'Minimum details to open a case',
            'form_text' => 'Do not enter passport, payment, password or API-key data here. The full questionnaire opens only after a protected case exists.',
            'name' => 'Applicant name', 'email' => 'Email (optional)', 'phone' => 'Phone', 'nationality' => 'Nationality', 'destination' => 'Destination country', 'date' => 'Approximate travel date (optional)', 'notes' => 'Context for the operator (optional)',
            'consent' => 'I understand that this is a manual review with no automatic issuance, and I consent to use of these contact details only for this case.',
            'submit' => 'Open manual eVisa case', 'side_title' => 'What happens next', 'side_one' => 'You receive a case access code; it is not an order or invoice.', 'side_two' => 'The complete intake collects passport, contact, travel and employment data in a protected step.', 'side_three' => 'The agency reviews the case manually; Telegram alerts are optional and consent-based.',
            'case_title' => 'Manual eVisa case', 'error' => 'The case could not be opened right now. Please try again later.', 'meta_title' => 'Manual eVisa case | Vazin Visa', 'meta_description' => 'A protected white-label manual eVisa case flow with no online payment.',
        ];
        return [
            'eyebrow' => 'پروندهٔ دستی eVisa',
            'title' => 'پروندهٔ امن eVisa را بدون فروش یا پرداخت آنلاین باز کنید.',
            'text' => 'این نمونهٔ عملی وایت‌لیبل است: ابتدا یک پروندهٔ بدون پرداخت و بدون وعدهٔ صدور ساخته می‌شود، سپس متقاضی فرم کامل محافظت‌شده را تکمیل می‌کند و آژانس آن را دستی بررسی می‌کند.',
            'form_title' => 'حداقل اطلاعات برای باز کردن پرونده',
            'form_text' => 'اینجا گذرنامه، اطلاعات پرداخت، رمز یا کلید API وارد نکنید. فرم کامل فقط پس از ایجاد پروندهٔ محافظت‌شده باز می‌شود.',
            'name' => 'نام متقاضی', 'email' => 'ایمیل (اختیاری)', 'phone' => 'شماره تماس', 'nationality' => 'تابعیت', 'destination' => 'کشور مقصد', 'date' => 'تاریخ تقریبی سفر (اختیاری)', 'notes' => 'زمینه برای کارشناس (اختیاری)',
            'consent' => 'می‌دانم این مسیر بررسی دستی است و صدور خودکار ندارد و موافقم اطلاعات تماس فقط برای همین پرونده به‌صورت امن استفاده شود.',
            'submit' => 'باز کردن پروندهٔ دستی eVisa', 'side_title' => 'بعد از این چه می‌شود؟', 'side_one' => 'کد دسترسی پرونده را می‌گیرید؛ این کد سفارش یا فاکتور نیست.', 'side_two' => 'فرم کامل در مرحلهٔ محافظت‌شده اطلاعات گذرنامه، تماس، سفر و شغل را دریافت می‌کند.', 'side_three' => 'آژانس پرونده را دستی بررسی می‌کند؛ هشدار Telegram فقط با رضایت فعال می‌شود.',
            'case_title' => 'پروندهٔ دستی eVisa', 'error' => 'فعلاً ایجاد پرونده ممکن نشد. کمی بعد دوباره تلاش کنید.', 'meta_title' => 'پروندهٔ دستی eVisa | Vazin Visa', 'meta_description' => 'روند وایت‌لیبل امن برای پروندهٔ دستی eVisa بدون پرداخت آنلاین.',
        ];
    }
}
