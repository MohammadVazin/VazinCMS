<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use PDO;
use Throwable;
use VazinCMS\{Audit,Auth,Database,RuntimePaths,Security,TravelAlertService,UiLocale,VisaApplicationService,View};

final class VisaCaseController
{
    private const STATUSES = ['new','awaiting_payment','paid','reviewing','documents_required','processing','completed','rejected','cancelled'];
    private const DOC_TYPES = ['passport','photo','insurance','invitation','education','financial','booking','other'];

    public function index(): void
    {
        $user = Auth::requireUser(['owner','admin']);
        $pdo = Database::connection();
        $status = (string)($_GET['status'] ?? '');
        $q = trim((string)($_GET['q'] ?? ''));
        $where = [];
        $args = [];
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'status=:status';
            $args['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(public_id LIKE :q OR full_name LIKE :q OR phone LIKE :q)';
            $args['q'] = '%' . mb_substr($q, 0, 100) . '%';
        }
        $sql = 'SELECT *, (SELECT COUNT(*) FROM visa_case_documents d WHERE d.order_id=travel_orders.id) AS document_count FROM travel_orders'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC LIMIT 300';
        $statement = $pdo->prepare($sql);
        $statement->execute($args);
        $orders = $statement->fetchAll();
        $stats = $pdo->query(
            "SELECT COUNT(*) AS total,SUM(CASE WHEN payment_status='not_required' THEN 1 ELSE 0 END) AS manual,"
            . "SUM(CASE WHEN status='documents_required' THEN 1 ELSE 0 END) AS docs,"
            . "SUM(CASE WHEN status IN ('new','paid','reviewing') THEN 1 ELSE 0 END) AS open FROM travel_orders"
        )->fetch();
        View::render('visa-cases', compact('user','orders','stats','status','q'));
    }

    public function show(int $id): void
    {
        $user = Auth::requireUser(['owner','admin']);
        $order = $this->byId($id);
        if (!$order) {
            http_response_code(404);
            View::render('error', ['title'=>'پرونده پیدا نشد','message'=>'این پرونده وجود ندارد.']);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $action = (string)($_POST['action'] ?? 'status');
            if ($action === 'status') $this->changeStatus($order, $user);
            elseif ($action === 'message') $this->message($order, $user);
            elseif ($action === 'document') $this->reviewDocument($order, $user);
            else throw new \InvalidArgumentException('عملیات پرونده معتبر نیست.');
            header('Location: /admin/visa-orders/' . $id . '?saved=1');
            return;
        }
        $events = $this->events($id, false);
        $documents = $this->documents($id);
        // Full passport/contact data is never available on the public tracking
        // page. This is an owner/admin-only route and its response must not be
        // retained by shared browser/proxy caches.
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        $application = (new VisaApplicationService())->forOperator((int) $order['id']);
        if (($application['state'] ?? '') === 'available') {
            try {
                Audit::log(
                    'visa.application_viewed',
                    'فرم محرمانهٔ eVisa برای بررسی باز شد',
                    (int) $user['id'],
                    ['order_id' => (int) $order['id'], 'schema_version' => (string) ($application['metadata']['schema_version'] ?? '')]
                );
            } catch (Throwable $error) {
                error_log('[VazinCMS] visa application view audit failed type=' . $error::class);
            }
        }
        View::render('visa-case', compact('user','order','events','documents','application'));
    }

    public function upload(string $locale, string $publicId): void
    {
        $locale = in_array($locale, ['fa','en','ru','ar'], true) ? $locale : UiLocale::detect();
        UiLocale::boot($locale);
        $order = $this->authorizedPublic($publicId, $locale);
        if (!$order) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            return;
        }
        Security::verifyCsrf();
        $type = (string)($_POST['document_type'] ?? 'other');
        if (!in_array($type, self::DOC_TYPES, true)) $type = 'other';
        try {
            $this->storeUpload($order, $type);
            $eventId = $this->event((int)$order['id'], 'document_uploaded', null, null, null, 'customer', null);
            $this->enqueueAlert($order, 'visa_document_uploaded', $eventId);
            header('Location: /' . $locale . '/order/' . $publicId . '?uploaded=1');
        } catch (Throwable $error) {
            $allowed = ['upload_file_invalid','upload_file_too_large','upload_type_invalid','upload_storage_unavailable','upload_store_failed'];
            $_SESSION['visa_upload_error_key'] = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'upload_failed_generic';
            error_log('[VazinCMS] visa upload failed type=' . $error::class);
            header('Location: /' . $locale . '/order/' . $publicId . '?upload=failed');
        }
    }

    public function download(int $id): void
    {
        $document = $this->document($id);
        if (!$document) {
            http_response_code(404);
            return;
        }
        $user = Auth::user();
        if (!$user || !in_array($user['role'] ?? '', ['owner','admin'], true)) {
            $order = $this->byId((int)$document['order_id']);
            $code = (string)($_SESSION['travel_order_access'][$order['public_id'] ?? ''] ?? '');
            if (!$order || $code === '' || !hash_equals((string)$order['access_hash'], hash('sha256', $code))) {
                http_response_code(403);
                return;
            }
        }
        $path = $this->storageDir() . '/' . $document['stored_name'];
        if (!is_file($path)) {
            http_response_code(404);
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . (string)$document['mime_type']);
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string)$document['original_name']));
        readfile($path);
    }

    /** @return array{caseEvents:array,caseDocuments:array,uploadError:string} */
    public function publicData(array $order): array
    {
        $key = (string)($_SESSION['visa_upload_error_key'] ?? '');
        unset($_SESSION['visa_upload_error_key']);
        $error = $key !== '' ? UiLocale::message($key) : '';
        $events = $this->events((int)$order['id'], true);
        foreach ($events as &$event) {
            $message = (string)($event['message'] ?? '');
            if ($event['event_type'] === 'document_uploaded') {
                $event['display_message'] = UiLocale::message('document_uploaded');
            } elseif ($event['event_type'] === 'document_reviewed') {
                $accepted = ($event['new_status'] ?? '') === 'accepted' || $message === 'مدرک تأیید شد.';
                $legacyPrefix = 'مدرک نیازمند اصلاح است:';
                $note = $accepted ? '' : (str_starts_with($message, $legacyPrefix) ? trim(substr($message, strlen($legacyPrefix))) : $message);
                $event['display_message'] = UiLocale::message($accepted ? 'document_accepted' : 'document_rejected') . ($note !== '' ? ' ' . $note : '');
            } else {
                $event['display_message'] = $message;
            }
        }
        unset($event);
        return ['caseEvents'=>$events,'caseDocuments'=>$this->documents((int)$order['id']),'uploadError'=>$error];
    }

    private function changeStatus(array $order, array $user): void
    {
        $new = (string)($_POST['status'] ?? '');
        if (!in_array($new, self::STATUSES, true)) throw new \InvalidArgumentException('وضعیت معتبر نیست.');
        $old = (string)$order['status'];
        Database::connection()->prepare('UPDATE travel_orders SET status=:status,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute(['status'=>$new,'id'=>$order['id']]);
        $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 2000);
        $eventId = $this->event((int)$order['id'], 'status_changed', $old, $new, $message ?: null, 'customer', (int)$user['id']);
        $order['status'] = $new;
        $this->enqueueAlert($order, 'visa_status_changed', $eventId);
        Audit::log('visa.case_status','وضعیت پرونده ویزا تغییر کرد',(int)$user['id'],['order_id'=>$order['id'],'from'=>$old,'to'=>$new]);
    }

    private function message(array $order, array $user): void
    {
        $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 3000);
        if ($message === '') throw new \InvalidArgumentException('پیام خالی است.');
        $visibility = ($_POST['visibility'] ?? 'customer') === 'internal' ? 'internal' : 'customer';
        $eventId = $this->event((int)$order['id'], 'message', null, null, $message, $visibility, (int)$user['id']);
        if ($visibility === 'customer') $this->enqueueAlert($order, 'visa_customer_message', $eventId);
    }

    private function reviewDocument(array $order, array $user): void
    {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $status = (string)($_POST['document_status'] ?? 'pending');
        if (!in_array($status, ['accepted','rejected'], true)) throw new \InvalidArgumentException('وضعیت مدرک معتبر نیست.');
        $note = mb_substr(trim((string)($_POST['reviewer_note'] ?? '')), 0, 1000);
        $update = Database::connection()->prepare(
            'UPDATE visa_case_documents SET status=:status,reviewer_note=:note,reviewed_at=CURRENT_TIMESTAMP WHERE id=:id AND order_id=:order_id'
        );
        $update->execute(['status'=>$status,'note'=>$note ?: null,'id'=>$documentId,'order_id'=>$order['id']]);
        if ($update->rowCount() !== 1) throw new \InvalidArgumentException('مدرک پرونده پیدا نشد.');
        $eventId = $this->event((int)$order['id'], 'document_reviewed', null, $status, $note ?: null, 'customer', (int)$user['id']);
        $this->enqueueAlert($order, 'visa_document_reviewed', $eventId);
    }

    private function storeUpload(array $order, string $type): int
    {
        $file = $_FILES['document'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            throw new \RuntimeException('upload_file_invalid');
        }
        $size = (int)$file['size'];
        if ($size < 1 || $size > 15_728_640) throw new \RuntimeException('upload_file_too_large');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($allowed[$mime])) throw new \RuntimeException('upload_type_invalid');
        $stored = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $directory = $this->storageDir();
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) throw new \RuntimeException('upload_storage_unavailable');
        if (!move_uploaded_file((string)$file['tmp_name'], $directory . '/' . $stored)) throw new \RuntimeException('upload_store_failed');
        $original = mb_substr(basename((string)$file['name']), 0, 255);
        $statement = Database::connection()->prepare(
            'INSERT INTO visa_case_documents(order_id,document_type,original_name,stored_name,mime_type,file_size,sha256) '
            . 'VALUES(:order_id,:type,:original,:stored,:mime,:size,:sha)'
        );
        $statement->execute([
            'order_id'=>$order['id'],'type'=>$type,'original'=>$original,'stored'=>$stored,'mime'=>$mime,
            'size'=>$size,'sha'=>hash_file('sha256', $directory . '/' . $stored),
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    private function authorizedPublic(string $id, string $locale): array|false
    {
        $order = $this->find($id);
        $code = (string)($_SESSION['travel_order_access'][$id] ?? '');
        if (!$order || $code === '' || !hash_equals((string)$order['access_hash'], hash('sha256', $code))) {
            http_response_code(403);
            header('Location: /' . $locale . '/account');
            return false;
        }
        return $order;
    }

    private function byId(int $id): array|false
    {
        $statement = Database::connection()->prepare('SELECT * FROM travel_orders WHERE id=:id');
        $statement->execute(['id'=>$id]);
        return $statement->fetch();
    }

    private function find(string $publicId): array|false
    {
        $statement = Database::connection()->prepare('SELECT * FROM travel_orders WHERE public_id=:id LIMIT 1');
        $statement->execute(['id'=>$publicId]);
        return $statement->fetch();
    }

    private function events(int $orderId, bool $public): array
    {
        $sql = 'SELECT * FROM visa_case_events WHERE order_id=:id' . ($public ? " AND visibility='customer'" : '') . ' ORDER BY created_at DESC,id DESC';
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['id'=>$orderId]);
        return $statement->fetchAll();
    }

    private function documents(int $orderId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM visa_case_documents WHERE order_id=:id ORDER BY created_at DESC');
        $statement->execute(['id'=>$orderId]);
        return $statement->fetchAll();
    }

    private function document(int $id): array|false
    {
        $statement = Database::connection()->prepare('SELECT * FROM visa_case_documents WHERE id=:id');
        $statement->execute(['id'=>$id]);
        return $statement->fetch();
    }

    private function event(int $orderId, string $type, ?string $old, ?string $new, ?string $message, string $visibility, ?int $userId): int
    {
        $pdo = Database::connection();
        $parameters = ['order_id'=>$orderId,'type'=>$type,'old'=>$old,'new'=>$new,'message'=>$message,'visibility'=>$visibility,'user'=>$userId];
        $sql = 'INSERT INTO visa_case_events(order_id,event_type,old_status,new_status,message,visibility,actor_user_id) '
            . 'VALUES(:order_id,:type,:old,:new,:message,:visibility,:user)';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $statement = $pdo->prepare($sql . ' RETURNING id');
            $statement->execute($parameters);
            return (int)$statement->fetchColumn();
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return (int)$pdo->lastInsertId();
    }

    private function enqueueAlert(array $order, string $eventType, int $eventId): void
    {
        try {
            TravelAlertService::enqueueOrderEvent($order, $eventType, $eventId);
        } catch (Throwable $error) {
            // Alert configuration must never block case work, and logs must not
            // include case data or Telegram payloads.
            error_log('[VazinCMS] visa alert enqueue failed event=' . $eventType . ' class=' . $error::class);
        }
    }

    private function storageDir(): string
    {
        return RuntimePaths::storage() . '/private/visa-documents';
    }
}
