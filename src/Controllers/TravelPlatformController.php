<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use InvalidArgumentException;
use PDO;
use Throwable;
use VazinCMS\Access;
use VazinCMS\Audit;
use VazinCMS\Database;
use VazinCMS\SecretStore;
use VazinCMS\Security;
use VazinCMS\View;

/**
 * White-label travel platform acquisition and connector configuration.
 *
 * This controller deliberately stores only contract-facing adapter metadata.
 * It does not call a provider, search inventory, or enable a supplier merely
 * because an administrator has created a configuration record.
 */
final class TravelPlatformController
{
    private const LOCALES = ['fa', 'ar', 'en', 'ru', 'tr', 'hy', 'kk', 'tg', 'zh'];
    private const MODES = ['lead_only', 'affiliate_redirect', 'manual_fulfilment', 'live_booking'];
    private const STATUSES = ['draft', 'contract_pending', 'sandbox', 'active', 'paused'];
    private const CAPABILITIES = ['search', 'quote', 'book', 'issue', 'voucher', 'cancel', 'refund', 'status'];
    private const SERVICES = ['flights', 'stays', 'packages', 'visa', 'b2b', 'corporate'];
    private const INQUIRY_STATUSES = ['new', 'reviewing', 'qualified', 'closed'];

    public function agency(string $locale): void
    {
        $locale = $this->locale($locale);
        $error = null;
        $submitted = (string) ($_GET['submitted'] ?? '') === '1';
        $form = $this->inquiryForm();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                Security::verifyCsrf();
                $inquiry = $this->validatedInquiry($form, $locale);
                $id = $this->insertInquiry(Database::connection(), $inquiry);
                try {
                    Audit::log('travel.agency_inquiry_created', 'درخواست همکاری آژانس سفر ثبت شد', null, [
                        'agency_inquiry_id' => $id,
                        'services' => json_decode($inquiry['services_json'], true) ?: [],
                    ]);
                } catch (Throwable $auditError) {
                    error_log('[VazinCMS travel agency inquiry audit] ' . $auditError::class);
                }
                header('Location: /' . rawurlencode($locale) . '/agency?submitted=1');
                return;
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('[VazinCMS travel agency inquiry] ' . $exception::class);
                $error = 'ثبت درخواست اکنون انجام نشد. لطفاً کمی بعد دوباره تلاش کنید.';
            }
        }

        // Public chrome owns $copy while composed templates are rendered in one
        // scope. Keep route-specific text under its own name so an active theme
        // cannot overwrite the agency form copy before it is evaluated.
        $agencyCopy = $this->publicCopy($locale);
        $base = rtrim((string) getenv('APP_URL'), '/');
        View::renderPublic('agency-request', [
            'locale' => $locale,
            'agencyCopy' => $agencyCopy,
            'form' => $form,
            'submitted' => $submitted,
            'error' => $error,
            'serviceOptions' => $this->serviceOptions($locale),
            'meta' => [
                'title' => $agencyCopy['meta_title'],
                'description' => $agencyCopy['meta_description'],
                'canonical' => $base === '' ? '' : $base . '/' . $locale . '/agency',
                'schema_type' => 'WebPage',
            ],
        ]);
    }

    public function connectors(): void
    {
        $user = Access::require('content');
        $pdo = Database::connection();
        $error = null;
        $message = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                Security::verifyCsrf();
                $action = (string) ($_POST['action'] ?? 'create');
                if (!in_array($action, ['create', 'update'], true)) {
                    throw new InvalidArgumentException('عملیات کانکتور معتبر نیست.');
                }
                $result = $this->saveConnector($pdo, $action, (int) $user['id']);
                try {
                    Audit::log(
                        'travel.connector_' . ($result['created'] ? 'created' : 'updated'),
                        $result['created'] ? 'کانکتور قراردادی سفر ساخته شد' : 'کانکتور قراردادی سفر به‌روزرسانی شد',
                        (int) $user['id'],
                        [
                            'connector_id' => $result['id'],
                            'provider_key' => $result['provider_key'],
                            'mode' => $result['mode'],
                            'status' => $result['status'],
                            'credentials_supplied' => $result['credentials_supplied'],
                        ]
                    );
                } catch (Throwable $auditError) {
                    error_log('[VazinCMS travel connector audit] ' . $auditError::class);
                }
                $message = $result['created']
                    ? 'کانکتور به‌صورت متادیتای قراردادی ذخیره شد؛ هیچ اتصال شبکه‌ای برقرار نشد.'
                    : 'تنظیمات کانکتور ذخیره شد؛ هیچ اتصال شبکه‌ای برقرار نشد.';
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('[VazinCMS travel connector] ' . $exception::class);
                $error = 'تنظیمات کانکتور ذخیره نشد.';
            }
        }

        $connectors = [];
        try {
            $rows = $pdo->query(
                'SELECT id,provider_key,label,mode,endpoint,capabilities_json,status,created_at,updated_at,' .
                "CASE WHEN credentials_sealed IS NULL OR credentials_sealed='' THEN 0 ELSE 1 END AS credentials_configured " .
                'FROM travel_provider_connections ORDER BY updated_at DESC,id DESC'
            )->fetchAll();
            foreach ($rows as $row) {
                $capabilities = json_decode((string) $row['capabilities_json'], true);
                $row['capabilities'] = is_array($capabilities)
                    ? array_values(array_intersect(self::CAPABILITIES, $capabilities))
                    : [];
                $connectors[] = $row;
            }
        } catch (Throwable $exception) {
            error_log('[VazinCMS travel connector list] ' . $exception::class);
            $error ??= 'فهرست کانکتورها در دسترس نیست. ابتدا migration این نسخه را اعمال کنید.';
        }

        View::render('travel-connectors', [
            'user' => $user,
            'connectors' => $connectors,
            'error' => $error,
            'message' => $message,
            'modes' => $this->modeLabels(),
            'statuses' => $this->statusLabels(),
            'capabilities' => $this->capabilityLabels(),
        ]);
    }

    /**
     * Operational inbox for white-label agency leads.
     *
     * This is intentionally a private, manually operated queue. It neither
     * creates a tenant nor starts a supplier, payment, mail, or Telegram flow.
     */
    public function inquiries(): void
    {
        $user = Access::require('content');
        $pdo = Database::connection();
        $error = null;
        $status = (string) ($_GET['status'] ?? '');
        if (!in_array($status, self::INQUIRY_STATUSES, true)) {
            $status = '';
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                Security::verifyCsrf();
                $id = (int) ($_POST['inquiry_id'] ?? 0);
                $updated = $this->updateInquiry($pdo, $id, (int) $user['id']);
                header('Location: /admin/agency-inquiries/' . $updated['id'] . '?saved=1');
                return;
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('[VazinCMS travel agency inquiry update] ' . $exception::class);
                $error = 'به‌روزرسانی درخواست انجام نشد.';
            }
        }

        $counts = array_fill_keys(self::INQUIRY_STATUSES, 0);
        $inquiries = [];
        try {
            foreach ($pdo->query('SELECT status,COUNT(*) AS total FROM agency_inquiries GROUP BY status')->fetchAll() as $row) {
                if (in_array($row['status'], self::INQUIRY_STATUSES, true)) {
                    $counts[$row['status']] = (int) $row['total'];
                }
            }
            $where = $status === '' ? '' : ' WHERE i.status=:status';
            $statement = $pdo->prepare(
                'SELECT i.*,u.name AS assigned_name,u.email AS assigned_email FROM agency_inquiries i ' .
                'LEFT JOIN users u ON u.id=i.assigned_user_id' . $where .
                " ORDER BY CASE i.status WHEN 'new' THEN 0 WHEN 'reviewing' THEN 1 WHEN 'qualified' THEN 2 ELSE 3 END," .
                ' i.updated_at DESC,i.id DESC LIMIT 200'
            );
            $statement->execute($status === '' ? [] : ['status' => $status]);
            foreach ($statement->fetchAll() as $inquiry) {
                $inquiries[] = $this->presentInquiry($inquiry);
            }
        } catch (Throwable $exception) {
            error_log('[VazinCMS travel agency inquiry list] ' . $exception::class);
            $error ??= 'صف درخواست‌های آژانس در دسترس نیست. ابتدا migration پلتفرم سفر را بررسی کنید.';
        }

        View::render('travel-agency-inquiries', [
            'user' => $user,
            'inquiries' => $inquiries,
            'counts' => $counts,
            'status' => $status,
            'statuses' => $this->inquiryStatusLabels(),
            'error' => $error,
        ]);
    }

    public function inquiry(int $id): void
    {
        $user = Access::require('content');
        $pdo = Database::connection();
        if ($id < 1) {
            $this->missingInquiry();
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                Security::verifyCsrf();
                $updated = $this->updateInquiry($pdo, $id, (int) $user['id']);
                header('Location: /admin/agency-inquiries/' . $updated['id'] . '?saved=1');
                return;
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('[VazinCMS travel agency inquiry detail update] ' . $exception::class);
                $error = 'به‌روزرسانی درخواست انجام نشد.';
            }
        }

        $statement = $pdo->prepare(
            'SELECT i.*,u.name AS assigned_name,u.email AS assigned_email FROM agency_inquiries i ' .
            'LEFT JOIN users u ON u.id=i.assigned_user_id WHERE i.id=:id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            $this->missingInquiry();
            return;
        }

        View::render('travel-agency-inquiry', [
            'user' => $user,
            'inquiry' => $this->presentInquiry($row),
            'staff' => $this->assignableStaff($pdo),
            'statuses' => $this->inquiryStatusLabels(),
            'saved' => (string) ($_GET['saved'] ?? '') === '1',
            'error' => $error ?? null,
        ]);
    }

    private function inquiryForm(): array
    {
        return [
            'organization' => trim((string) ($_POST['organization'] ?? '')),
            'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'country' => trim((string) ($_POST['country'] ?? '')),
            'website' => trim((string) ($_POST['website'] ?? '')),
            'services' => array_values(array_intersect(
                self::SERVICES,
                array_map('strval', (array) ($_POST['services'] ?? []))
            )),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
    }

    private function validatedInquiry(array $form, string $locale): array
    {
        if (mb_strlen($form['organization']) < 2 || mb_strlen($form['organization']) > 160) {
            throw new InvalidArgumentException('نام آژانس یا کسب‌وکار را بین ۲ تا ۱۶۰ نویسه وارد کنید.');
        }
        if (mb_strlen($form['contact_name']) < 2 || mb_strlen($form['contact_name']) > 160) {
            throw new InvalidArgumentException('نام شخص تماس را بین ۲ تا ۱۶۰ نویسه وارد کنید.');
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 190) {
            throw new InvalidArgumentException('نشانی ایمیل معتبر نیست.');
        }
        if (preg_match('/^[0-9+().\\-\\s]{5,64}$/u', $form['phone']) !== 1) {
            throw new InvalidArgumentException('شمارهٔ تماس معتبر نیست.');
        }
        if (mb_strlen($form['country']) < 2 || mb_strlen($form['country']) > 100) {
            throw new InvalidArgumentException('کشور فعالیت را وارد کنید.');
        }
        if ($form['website'] !== '') {
            $website = parse_url($form['website']);
            if (
                !is_array($website)
                || !in_array(strtolower((string) ($website['scheme'] ?? '')), ['http', 'https'], true)
                || empty($website['host'])
                || isset($website['user'])
                || isset($website['pass'])
                || mb_strlen($form['website']) > 2048
            ) {
                throw new InvalidArgumentException('نشانی وب‌سایت باید یک URL معتبر HTTP یا HTTPS باشد.');
            }
        }
        if ($form['services'] === []) {
            throw new InvalidArgumentException('حداقل یک حوزهٔ خدمت را انتخاب کنید.');
        }
        if (mb_strlen($form['notes']) > 2000) {
            throw new InvalidArgumentException('توضیحات حداکثر ۲۰۰۰ نویسه است.');
        }
        if ((string) ($_POST['privacy_consent'] ?? '') !== '1') {
            throw new InvalidArgumentException('برای ارسال درخواست، رضایت از استفادهٔ اطلاعات تماس لازم است.');
        }

        return [
            'organization' => $form['organization'],
            'contact_name' => $form['contact_name'],
            'email' => $form['email'],
            'phone' => $form['phone'],
            'country' => $form['country'],
            'website' => $form['website'],
            'locale' => $locale,
            'services_json' => json_encode($form['services'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'notes' => $form['notes'],
            'status' => 'new',
        ];
    }

    private function insertInquiry(PDO $pdo, array $inquiry): int
    {
        $sql = 'INSERT INTO agency_inquiries(organization,contact_name,email,phone,country,website,locale,services_json,notes,status) ' .
            'VALUES(:organization,:contact_name,:email,:phone,:country,:website,:locale,:services_json,:notes,:status)';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $statement = $pdo->prepare($sql . ' RETURNING id');
            $statement->execute($inquiry);
            $id = (int) $statement->fetchColumn();
        } else {
            $statement = $pdo->prepare($sql);
            $statement->execute($inquiry);
            $id = (int) $pdo->lastInsertId();
        }
        if ($id < 1) {
            throw new \RuntimeException('شناسهٔ درخواست ساخته نشد.');
        }
        return $id;
    }

    private function updateInquiry(PDO $pdo, int $id, int $actorId): array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('درخواست آژانس انتخاب‌شده معتبر نیست.');
        }
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, self::INQUIRY_STATUSES, true)) {
            throw new InvalidArgumentException('وضعیت درخواست آژانس معتبر نیست.');
        }
        $assignee = (int) ($_POST['assigned_user_id'] ?? 0);
        if ($assignee > 0) {
            $check = $pdo->prepare("SELECT id FROM users WHERE id=:id AND status='active' AND role IN ('owner','admin') LIMIT 1");
            $check->execute(['id' => $assignee]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException('مسئول انتخاب‌شده یک مدیر فعال نیست.');
            }
        }

        $statement = $pdo->prepare(
            'UPDATE agency_inquiries SET status=:status,assigned_user_id=:assigned_user_id,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
        );
        $statement->execute([
            'status' => $status,
            'assigned_user_id' => $assignee > 0 ? $assignee : null,
            'id' => $id,
        ]);
        if ($statement->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT 1 FROM agency_inquiries WHERE id=:id LIMIT 1');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) {
                throw new InvalidArgumentException('درخواست آژانس پیدا نشد.');
            }
        }
        try {
            Audit::log('travel.agency_inquiry_updated', 'وضعیت یا مسئول درخواست آژانس به‌روزرسانی شد', $actorId, [
                'agency_inquiry_id' => $id,
                'status' => $status,
                'assigned_user_id' => $assignee > 0 ? $assignee : null,
            ]);
        } catch (Throwable $auditError) {
            error_log('[VazinCMS travel agency inquiry audit] ' . $auditError::class);
        }

        return ['id' => $id, 'status' => $status, 'assigned_user_id' => $assignee > 0 ? $assignee : null];
    }

    private function assignableStaff(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id,name,email,role FROM users WHERE status='active' AND role IN ('owner','admin') " .
            "ORDER BY CASE role WHEN 'owner' THEN 0 ELSE 1 END,name,id"
        )->fetchAll();
    }

    private function presentInquiry(array $inquiry): array
    {
        $services = json_decode((string) ($inquiry['services_json'] ?? '[]'), true);
        $labels = $this->serviceOptions('fa');
        $inquiry['service_labels'] = [];
        if (is_array($services)) {
            foreach (array_values(array_intersect(self::SERVICES, array_map('strval', $services))) as $service) {
                $inquiry['service_labels'][] = $labels[$service] ?? $service;
            }
        }
        $website = trim((string) ($inquiry['website'] ?? ''));
        $parts = $website === '' ? null : parse_url($website);
        $inquiry['website_url'] = is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            ? $website
            : '';
        return $inquiry;
    }

    private function inquiryStatusLabels(): array
    {
        return [
            'new' => 'جدید',
            'reviewing' => 'در حال بررسی',
            'qualified' => 'واجد شرایط',
            'closed' => 'بسته‌شده',
        ];
    }

    private function missingInquiry(): void
    {
        http_response_code(404);
        View::render('error', ['title' => 'درخواست آژانس پیدا نشد', 'message' => 'این درخواست وجود ندارد یا دیگر در دسترس نیست.']);
    }

    private function saveConnector(PDO $pdo, string $action, int $userId): array
    {
        $input = $this->validatedConnector();
        $id = $action === 'update' ? (int) ($_POST['connector_id'] ?? 0) : 0;
        if ($action === 'update' && $id < 1) {
            throw new InvalidArgumentException('کانکتور انتخاب‌شده معتبر نیست.');
        }

        $pdo->beginTransaction();
        try {
            $created = $action === 'create';
            $existing = null;
            if ($created) {
                $id = $this->insertConnector($pdo, $input, $userId);
            } else {
                $statement = $pdo->prepare(
                    'SELECT id,credentials_sealed FROM travel_provider_connections WHERE id=:id LIMIT 1'
                );
                $statement->execute(['id' => $id]);
                $existing = $statement->fetch();
                if (!is_array($existing)) {
                    throw new InvalidArgumentException('کانکتور انتخاب‌شده پیدا نشد.');
                }
            }

            $credentialsSealed = $existing['credentials_sealed'] ?? null;
            if ($input['clear_credentials']) {
                $credentialsSealed = null;
            }
            if ($input['credentials'] !== '') {
                $credentialsSealed = SecretStore::seal($input['credentials'], $this->connectorContext($id));
            }

            $statement = $pdo->prepare(
                'UPDATE travel_provider_connections SET provider_key=:provider_key,label=:label,mode=:mode,' .
                'endpoint=:endpoint,capabilities_json=:capabilities_json,credentials_sealed=:credentials_sealed,' .
                'status=:status,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
            );
            $statement->execute([
                'provider_key' => $input['provider_key'],
                'label' => $input['label'],
                'mode' => $input['mode'],
                'endpoint' => $input['endpoint'],
                'capabilities_json' => $input['capabilities_json'],
                'credentials_sealed' => $credentialsSealed,
                'status' => $input['status'],
                'id' => $id,
            ]);
            $pdo->commit();

            return [
                'id' => $id,
                'created' => $created,
                'provider_key' => $input['provider_key'],
                'mode' => $input['mode'],
                'status' => $input['status'],
                'credentials_supplied' => $input['credentials'] !== '',
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function insertConnector(PDO $pdo, array $input, int $userId): int
    {
        $parameters = [
            'provider_key' => $input['provider_key'],
            'label' => $input['label'],
            'mode' => $input['mode'],
            'endpoint' => $input['endpoint'],
            'capabilities_json' => $input['capabilities_json'],
            'status' => $input['status'],
            'created_by' => $userId,
        ];
        $sql = 'INSERT INTO travel_provider_connections(provider_key,label,mode,endpoint,capabilities_json,credentials_sealed,status,created_by) ' .
            'VALUES(:provider_key,:label,:mode,:endpoint,:capabilities_json,NULL,:status,:created_by)';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $statement = $pdo->prepare($sql . ' RETURNING id');
            $statement->execute($parameters);
            $id = (int) $statement->fetchColumn();
        } else {
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            $id = (int) $pdo->lastInsertId();
        }
        if ($id < 1) {
            throw new \RuntimeException('شناسهٔ کانکتور ساخته نشد.');
        }
        return $id;
    }

    private function validatedConnector(): array
    {
        $providerKey = strtolower(trim((string) ($_POST['provider_key'] ?? '')));
        $label = trim((string) ($_POST['label'] ?? ''));
        $mode = (string) ($_POST['mode'] ?? 'lead_only');
        $status = (string) ($_POST['status'] ?? 'draft');
        $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
        $capabilities = array_values(array_intersect(
            self::CAPABILITIES,
            array_map('strval', (array) ($_POST['capabilities'] ?? []))
        ));
        $credentials = trim((string) ($_POST['credentials'] ?? ''));

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $providerKey) !== 1) {
            throw new InvalidArgumentException('شناسهٔ فنی کانکتور باید با حروف کوچک، عدد و خط تیره باشد.');
        }
        if (mb_strlen($label) < 2 || mb_strlen($label) > 120) {
            throw new InvalidArgumentException('نام نمایشی کانکتور باید بین ۲ تا ۱۲۰ نویسه باشد.');
        }
        if (!in_array($mode, self::MODES, true) || !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('حالت فروش یا وضعیت کانکتور معتبر نیست.');
        }
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            if (
                !is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || mb_strlen($endpoint) > 2048
            ) {
                throw new InvalidArgumentException('نشانی قراردادی باید URL امن HTTPS باشد.');
            }
        }
        if (mb_strlen($credentials) > 4000) {
            throw new InvalidArgumentException('مقدار محرمانهٔ کانکتور بیش از حد بلند است.');
        }
        if ($mode === 'lead_only' && array_intersect($capabilities, ['book', 'issue', 'voucher', 'cancel', 'refund']) !== []) {
            throw new InvalidArgumentException('حالت lead-only نباید قابلیت رزرو یا صدور اعلام کند.');
        }
        if ($mode === 'live_booking' && $status === 'active') {
            if ($endpoint === '' || !in_array('book', $capabilities, true)) {
                throw new InvalidArgumentException('برای ثبت active در رزرو زنده، endpoint قراردادی و قابلیت book لازم است.');
            }
        }
        if ($status === 'active' && (string) ($_POST['contract_confirmed'] ?? '') !== '1') {
            throw new InvalidArgumentException('فعال‌سازی فقط پس از تأیید قرارداد و مسئول فروش ممکن است.');
        }

        return [
            'provider_key' => $providerKey,
            'label' => $label,
            'mode' => $mode,
            'status' => $status,
            'endpoint' => $endpoint,
            'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'credentials' => $credentials,
            'clear_credentials' => (string) ($_POST['clear_credentials'] ?? '') === '1',
        ];
    }

    private function locale(string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'fa';
    }

    private function connectorContext(int $id): string
    {
        return 'travel.connector.' . $id;
    }

    private function modeLabels(): array
    {
        return [
            'lead_only' => 'فقط دریافت سرنخ',
            'affiliate_redirect' => 'ارجاع افیلیت',
            'manual_fulfilment' => 'انجام دستی',
            'live_booking' => 'رزرو زنده با قرارداد',
        ];
    }

    private function statusLabels(): array
    {
        return [
            'draft' => 'پیش‌نویس',
            'contract_pending' => 'در انتظار قرارداد',
            'sandbox' => 'آزمایشی',
            'active' => 'فعالِ قراردادی',
            'paused' => 'متوقف',
        ];
    }

    private function capabilityLabels(): array
    {
        return [
            'search' => 'جست‌وجو',
            'quote' => 'قیمت‌گیری',
            'book' => 'رزرو',
            'issue' => 'صدور',
            'voucher' => 'واچر',
            'cancel' => 'لغو',
            'refund' => 'استرداد',
            'status' => 'پیگیری وضعیت',
        ];
    }

    private function serviceOptions(string $locale): array
    {
        $fa = [
            'flights' => 'پرواز',
            'stays' => 'هتل، هاستل و اقامت',
            'packages' => 'تور و بستهٔ سفر',
            'visa' => 'ویزای الکترونیکی و پروندهٔ دستی',
            'b2b' => 'فروش B2B',
            'corporate' => 'سفر سازمانی',
        ];
        $ru = [
            'flights' => 'Авиабилеты',
            'stays' => 'Отели, хостелы и проживание',
            'packages' => 'Туры и пакеты',
            'visa' => 'Электронные и ручные визовые кейсы',
            'b2b' => 'B2B-продажи',
            'corporate' => 'Корпоративные поездки',
        ];
        $en = [
            'flights' => 'Flights',
            'stays' => 'Hotels, hostels and stays',
            'packages' => 'Tours and packages',
            'visa' => 'eVisa and manual visa cases',
            'b2b' => 'B2B sales',
            'corporate' => 'Corporate travel',
        ];
        return $locale === 'ru' ? $ru : ($locale === 'en' ? $en : $fa);
    }

    private function publicCopy(string $locale): array
    {
        if ($locale === 'ru') {
            return [
                'eyebrow' => 'Запрос для агентства',
                'title' => 'Подготовим white-label контур для вашего туристического бизнеса.',
                'text' => 'Оставьте рабочие контакты. На первом разговоре мы согласуем бренд, модель продаж, лицензии и только затем — контрактные адаптеры.',
                'success_title' => 'Запрос получен',
                'success_text' => 'Мы не создали продажу и не передали данные поставщику. Команда свяжется с вами для проектного разговора.',
                'form_title' => 'Расскажите о вашем агентстве',
                'form_text' => 'Не указывайте пароли, ключи, паспортные или платёжные данные.',
                'consent' => 'Я согласен(на), чтобы Vazin Online использовал контактные данные только для ответа на этот запрос.',
                'submit' => 'Отправить запрос',
                'side_title' => 'Что обсудим',
                'side_items' => ['Ваш домен, бренд, языки и правовой продавец.', 'Режим: лиды, affiliate, ручное исполнение или live booking.', 'Контрактные источники, ответственность за выдачу и добровольные Telegram-уведомления.'],
                'meta_title' => 'White-label для туристических агентств | Vazin Travel',
                'meta_description' => 'Запросите демонстрацию white-label платформы Vazin Travel для лицензированного туристического бизнеса.',
            ];
        }
        if ($locale === 'en') {
            return [
                'eyebrow' => 'Agency enquiry',
                'title' => 'Set up a white-label operating layer for your travel business.',
                'text' => 'Leave your work contacts. We first align brand, sales model, licensing and only then contract-backed adapters.',
                'success_title' => 'Your enquiry is received',
                'success_text' => 'No sale was created and no supplier received your data. Our team will contact you for a project conversation.',
                'form_title' => 'Tell us about your agency',
                'form_text' => 'Do not submit passwords, API keys, passport data or payment data.',
                'consent' => 'I agree that Vazin Online may use these contact details only to respond to this enquiry.',
                'submit' => 'Send agency enquiry',
                'side_title' => 'What we will discuss',
                'side_items' => ['Your domain, brand, languages and legal seller.', 'Lead-only, affiliate redirect, manual fulfilment or live booking.', 'Contract-backed sources, issuance responsibility and optional Telegram alerts.'],
                'meta_title' => 'Travel agency white-label | Vazin Travel',
                'meta_description' => 'Request a demo of Vazin Travel white-label platform for licensed travel businesses.',
            ];
        }
        return [
            'eyebrow' => 'درخواست آژانس',
            'title' => 'لایهٔ عملیاتی وایت‌لیبلِ کسب‌وکار سفر شما را آماده می‌کنیم.',
            'text' => 'اطلاعات کاری آژانس را بگذارید؛ ابتدا برند، مدل فروش و مجوزها را مشخص می‌کنیم و سپس دربارهٔ اتصال‌های قراردادی تصمیم می‌گیریم.',
            'success_title' => 'درخواست شما دریافت شد',
            'success_text' => 'هیچ فروش یا اتصالی به تأمین‌کننده ایجاد نشده و اطلاعات شما جایی ارسال نشده است. برای گفت‌وگوی پروژه‌ای با شما تماس می‌گیریم.',
            'form_title' => 'از آژانس خود بگویید',
            'form_text' => 'رمز، کلید API، اطلاعات گذرنامه یا دادهٔ پرداخت را در این فرم وارد نکنید.',
            'consent' => 'موافقم Vazin Online فقط برای پاسخ به این درخواست از اطلاعات تماس من استفاده کند.',
            'submit' => 'ارسال درخواست آژانس',
            'side_title' => 'در گفت‌وگوی اول چه مشخص می‌شود؟',
            'side_items' => ['دامنه، برند، زبان‌ها و فروشندهٔ حقوقی خود آژانس.', 'حالت lead-only، ارجاع افیلیت، انجام دستی یا رزرو زنده.', 'منابع قراردادی، مسئولیت صدور و هشدارهای اختیاری Telegram.'],
            'meta_title' => 'وایت‌لیبل آژانس مسافرتی | Vazin Travel',
            'meta_description' => 'درخواست دمو برای پلتفرم وایت‌لیبل Vazin Travel ویژهٔ کسب‌وکارهای دارای مجوز سفر.',
        ];
    }
}
