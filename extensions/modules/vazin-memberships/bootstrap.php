<?php
declare(strict_types=1);

require_once __DIR__ . '/MembershipService.php';

use VazinCMS\{Access, Auth, ModuleContext, Security, View};
use VazinCMS\Modules\Memberships\MembershipService;

return static function (ModuleContext $module): void {
    $service = static fn(): MembershipService => new MembershipService($module->db());
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $module->registerBlock('paywall', [
        'label' => 'قفل محتوای ویژه اعضا (VIP Paywall)',
        'category' => 'memberships',
        'schema' => [
            'plan_key' => 'string',
            'teaser' => 'string',
            'vip_content' => 'string:required',
        ],
    ], static function (array $data) use ($service, $esc): string {
        $planKey = trim((string) ($data['plan_key'] ?? ''));
        $teaser = trim((string) ($data['teaser'] ?? 'این بخش ویژه اعضای دارای اشتراک فعال است.'));
        $user = Auth::user();
        $email = is_array($user) ? (string) ($user['email'] ?? '') : '';
        $isOwnerOrUnlocked = (is_array($user) && in_array($user['role'] ?? '', ['owner', 'admin'], true))
            || ($email !== '' && $service()->hasActiveAccess($email, $planKey !== '' ? $planKey : null));

        if ($isOwnerOrUnlocked) {
            return '<div class="vz-vip-unlocked">' . $esc((string) ($data['vip_content'] ?? '')) . '</div>';
        }
        return '<aside class="vz-vip-paywall"><strong>محتوای ویژه مشترکین</strong><p>' . $esc($teaser) . '</p></aside>';
    });

    $module->api('GET', 'plans', static fn(): array => ['items' => $service()->listPlans()]);

    $module->any('/admin/memberships', static function () use ($service): void {
        $user = Access::require('sales');
        $svc = $service();
        $message = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                if (($_POST['action'] ?? 'create_plan') === 'grant') {
                    $svc->grantSubscription(
                        (string) ($_POST['plan_key'] ?? ''),
                        (string) ($_POST['member_email'] ?? ''),
                        (string) ($_POST['member_name'] ?? 'عضو ویژه')
                    );
                    $message = 'اشتراک ویژه برای کاربر فعال شد.';
                } else {
                    $svc->createPlan($_POST);
                    $message = 'پلن اشتراک جدید ایجاد شد.';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        View::render('memberships-admin', [
            'title' => 'اشتراک ویژه (VIP)',
            'user' => $user,
            'plans' => $svc->listPlans(),
            'subscriptions' => $svc->listSubscriptions(),
            'message' => $message,
            'error' => $error,
        ]);
    });
};
