<?php
declare(strict_types=1);

namespace VazinCMS\Modules\Booking;

use InvalidArgumentException;
use RuntimeException;
use VazinCMS\{ExtensionRuntime, ModuleDatabase, PageCacheService};

final class BookingService
{
    private bool $ready = false;

    public function __construct(private ModuleDatabase $db)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->db->ensureTable('services', [
            'slug'              => 'string:160',
            'title'             => 'string:255',
            'description'       => 'text',
            'duration_minutes'  => 'int:default=30',
            'capacity_per_slot' => 'int:default=1',
            'price'             => 'decimal',
            'currency'          => 'string:10:default=IRR',
            'status'            => 'string:20:default=active',
        ], [['slug']]);

        $this->db->ensureTable('reservations', [
            'booking_code'   => 'string:60',
            'service_id'     => 'bigint',
            'customer_name'  => 'string:160',
            'customer_email' => 'string:180',
            'customer_phone' => 'string:40',
            'slot_date'      => 'string:20',
            'slot_time'      => 'string:10',
            'notes'          => 'text',
            'status'         => 'string:20:default=confirmed',
        ], [['booking_code']]);

        $this->ready = true;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createService(array $input): array
    {
        $this->ensureSchema();
        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) < 2) {
            throw new InvalidArgumentException('عنوان خدمت باید حداقل ۲ کاراکتر باشد.');
        }
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9\x{0600}-\x{06FF}-]+/u', '-', (string) ($input['slug'] ?? $title)), '-'));
        if ($slug === '') $slug = 'srv-' . bin2hex(random_bytes(3));

        $id = $this->db->insert('services', [
            'slug' => mb_substr($slug, 0, 155),
            'title' => $title,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 2000),
            'duration_minutes' => max(5, min(720, (int) ($input['duration_minutes'] ?? 30))),
            'capacity_per_slot' => max(1, min(500, (int) ($input['capacity_per_slot'] ?? 1))),
            'price' => max(0.0, (float) ($input['price'] ?? 0)),
            'currency' => strtoupper(trim((string) ($input['currency'] ?? 'IRR'))),
            'status' => 'active',
        ]);
        PageCacheService::invalidateTag('module:vazin-booking');
        return $this->db->find('services', $id) ?? ['id' => $id];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listServices(bool $activeOnly = true): array
    {
        $this->ensureSchema();
        return $this->db->where('services', $activeOnly ? ['status' => 'active'] : [], 'id DESC', 100);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function reserve(array $input): array
    {
        $this->ensureSchema();
        $serviceId = (int) ($input['service_id'] ?? 0);
        $service = $this->db->find('services', $serviceId);
        if ($service === null || ($service['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('خدمت انتخاب‌شده فعال نیست.');
        }
        $name = trim((string) ($input['customer_name'] ?? ''));
        $email = strtolower(trim((string) ($input['customer_email'] ?? '')));
        $date = trim((string) ($input['slot_date'] ?? ''));
        $time = trim((string) ($input['slot_time'] ?? ''));
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('نام و ایمیل معتبر متقاضی الزامی است.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            throw new InvalidArgumentException('تاریخ یا ساعت نوبت معتبر نیست (YYYY-MM-DD و HH:MM).');
        }

        $taken = $this->db->count('reservations', [
            'service_id' => $serviceId,
            'slot_date' => $date,
            'slot_time' => $time,
            'status' => 'confirmed',
        ]);
        if ($taken >= (int) $service['capacity_per_slot']) {
            throw new RuntimeException('ظرفیت این بازه زمانی تکمیل شده است.');
        }

        $code = 'BK-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $id = $this->db->insert('reservations', [
            'booking_code' => $code,
            'service_id' => $serviceId,
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => mb_substr(trim((string) ($input['customer_phone'] ?? '')), 0, 40),
            'slot_date' => $date,
            'slot_time' => $time,
            'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000),
            'status' => 'confirmed',
        ]);
        ExtensionRuntime::emit('booking.reserved', ['reservation_id' => $id, 'booking_code' => $code]);
        return $this->db->find('reservations', $id) ?? ['id' => $id, 'booking_code' => $code];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReservations(int $limit = 50): array
    {
        $this->ensureSchema();
        return $this->db->where('reservations', [], 'id DESC', $limit);
    }
}
