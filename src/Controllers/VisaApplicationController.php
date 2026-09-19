<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\Database;
use VazinCMS\Security;
use VazinCMS\TravelAlertService;
use VazinCMS\UiLocale;
use VazinCMS\View;
use VazinCMS\VisaApplicationService;

final class VisaApplicationController
{
    private const EDITABLE_STATUSES = ['new', 'awaiting_payment', 'paid', 'documents_required'];

    public function form(string $locale, string $publicId): void
    {
        $locale = $this->locale($locale);
        UiLocale::boot($locale);
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        $order = $this->authorizedOrder($publicId, $locale);
        if ($order === false) return;

        $service = new VisaApplicationService();
        $submitted = $service->hasSubmission((int) $order['id']);
        $editable = in_array((string) $order['status'], self::EDITABLE_STATUSES, true);
        $errors = [];
        $saved = isset($_GET['submitted']) && $submitted;
        $replace = isset($_GET['replace']);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            if (!$editable) {
                http_response_code(409);
                $errors['_form'] = 'این پرونده دیگر برای ویرایش اطلاعات eVisa باز نیست.';
            } else {
                try {
                    $validated = $service->validate($_POST);
                    $errors = $validated['errors'];
                    if ($errors === []) {
                        $eventId = $service->save($order, $validated['payload']);
                        try {
                            TravelAlertService::enqueueOrderEvent($order, 'visa_application_received', $eventId);
                        } catch (\Throwable $alertError) {
                            // A delivery configuration issue must never roll back
                            // an already committed encrypted application intake.
                            error_log('[VazinCMS] visa application alert enqueue failed type=' . $alertError::class);
                        }
                        header('Location: /' . rawurlencode($locale) . '/order/' . rawurlencode((string) $order['public_id']) . '/application?submitted=1');
                        return;
                    }
                } catch (\Throwable $error) {
                    // Never include form values or an exception message in logs.
                    error_log('[VazinCMS] visa application save failed type=' . get_class($error));
                    $errors['_form'] = 'ثبت امن اطلاعات در حال حاضر ممکن نیست. لطفاً دوباره تلاش کنید.';
                }
            }
        }

        $showForm = $editable && (!$submitted || $replace || $errors !== []);
        View::renderPublic('visa-application', [
            'locale' => $locale,
            'order' => $order,
            'errors' => $errors,
            'submitted' => $submitted || $saved,
            'editable' => $editable,
            'showForm' => $showForm,
            'meta' => [
                'title' => $locale === 'ru' ? 'Анкета eVisa' : ($locale === 'en' ? 'eVisa application form' : 'فرم درخواست eVisa'),
                'description' => $locale === 'ru' ? 'Защищённая анкета для визового дела.' : ($locale === 'en' ? 'Secure application form for a visa case.' : 'فرم امن تکمیل اطلاعات پرونده ویزا.'),
                'robots' => 'noindex,nofollow',
            ],
        ]);
    }

    private function authorizedOrder(string $publicId, string $locale): array|false
    {
        $publicId = strtoupper($publicId);
        $statement = Database::connection()->prepare('SELECT * FROM travel_orders WHERE public_id=:id LIMIT 1');
        $statement->execute(['id' => $publicId]);
        $order = $statement->fetch();
        $access = $_SESSION['travel_order_access'] ?? [];
        $code = is_array($access) ? (string) ($access[$publicId] ?? '') : '';
        if (
            !$order
            || (string) ($order['service_type'] ?? '') !== 'visa'
            || $code === ''
            || !hash_equals((string) $order['access_hash'], hash('sha256', $code))
        ) {
            http_response_code(403);
            header('Location: /' . rawurlencode($locale) . '/account');
            return false;
        }
        return $order;
    }

    private function locale(string $value): string
    {
        return in_array($value, ['fa', 'ru', 'en', 'ar'], true) ? $value : UiLocale::detect();
    }
}
