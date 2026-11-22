<?php
declare(strict_types=1);

namespace VazinCMS\Modules\Memberships;

use InvalidArgumentException;
use VazinCMS\ModuleDatabase;

final class MembershipService
{
    private bool $ready = false;

    public function __construct(private ModuleDatabase $db)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) return;
        $this->db->ensureTable('plans', [
            'plan_key'      => 'string:60',
            'title'         => 'string:160',
            'duration_days' => 'int:default=30',
            'price'         => 'decimal',
            'currency'      => 'string:10:default=IRR',
            'status'        => 'string:20:default=active',
        ], [['plan_key']]);

        $this->db->ensureTable('subscriptions', [
            'plan_key'     => 'string:60',
            'member_email' => 'string:180',
            'member_name'  => 'string:140',
            'expires_at'   => 'string:30',
            'status'       => 'string:20:default=active',
        ]);
        $this->ready = true;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createPlan(array $input): array
    {
        $this->ensureSchema();
        $key = strtolower(trim((string) ($input['plan_key'] ?? '')));
        $title = trim((string) ($input['title'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,58}$/', $key) !== 1 || mb_strlen($title) < 2) {
            throw new InvalidArgumentException('شناسه یا عنوان پلن اشتراک معتبر نیست.');
        }
        $id = $this->db->insert('plans', [
            'plan_key' => $key,
            'title' => $title,
            'duration_days' => max(1, (int) ($input['duration_days'] ?? 30)),
            'price' => max(0.0, (float) ($input['price'] ?? 0)),
            'currency' => strtoupper(trim((string) ($input['currency'] ?? 'IRR'))),
            'status' => 'active',
        ]);
        return $this->db->find('plans', $id) ?? ['id' => $id];
    }

    /**
     * @return array<string,mixed>
     */
    public function grantSubscription(string $planKey, string $email, string $name = 'کاربر ویژه'): array
    {
        $this->ensureSchema();
        $plan = $this->db->findOneBy('plans', ['plan_key' => strtolower(trim($planKey)), 'status' => 'active']);
        if ($plan === null) {
            throw new InvalidArgumentException('پلن اشتراک موردنظر یافت نشد.');
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('ایمیل عضو معتبر نیست.');
        }
        $days = max(1, (int) $plan['duration_days']);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
        $id = $this->db->insert('subscriptions', [
            'plan_key' => (string) $plan['plan_key'],
            'member_email' => $email,
            'member_name' => mb_substr(trim($name), 0, 140),
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);
        return $this->db->find('subscriptions', $id) ?? ['id' => $id];
    }

    public function hasActiveAccess(string $email, ?string $planKey = null): bool
    {
        $this->ensureSchema();
        $email = strtolower(trim($email));
        if ($email === '') return false;
        $criteria = ['member_email' => $email, 'status' => 'active'];
        if ($planKey !== null && $planKey !== '') {
            $criteria['plan_key'] = strtolower(trim($planKey));
        }
        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->db->where('subscriptions', $criteria, 'id DESC', 20) as $sub) {
            if ((string) ($sub['expires_at'] ?? '') >= $now) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPlans(): array
    {
        $this->ensureSchema();
        return $this->db->where('plans', [], 'id DESC', 50);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSubscriptions(): array
    {
        $this->ensureSchema();
        return $this->db->where('subscriptions', [], 'id DESC', 100);
    }
}
