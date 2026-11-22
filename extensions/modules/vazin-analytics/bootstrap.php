<?php
declare(strict_types=1);

require_once __DIR__ . '/AnalyticsService.php';

use VazinCMS\{Access, ModuleContext, View};
use VazinCMS\Modules\Analytics\AnalyticsService;

return static function (ModuleContext $module): void {
    $service = static fn(): AnalyticsService => new AnalyticsService($module->db());

    $module->on('page.viewed', static function (array $payload) use ($service): void {
        $service()->recordHit(
            (string) ($payload['path'] ?? '/'),
            (string) ($payload['referrer'] ?? ''),
            (string) ($payload['user_agent'] ?? ''),
            (string) ($payload['ip'] ?? '')
        );
    });

    $module->api('POST', 'collect', static function (array $input) use ($service): array {
        $service()->recordHit(
            (string) ($input['path'] ?? '/'),
            (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
        return ['recorded' => true];
    });

    $module->api('GET', 'summary', static fn(): array => $service()->summary(500), true);

    $module->any('/admin/analytics', static function () use ($service): void {
        $user = Access::require('system');
        View::render('analytics-admin', [
            'title' => 'آمار و آنالیتیکس',
            'user' => $user,
            'report' => $service()->summary(1000),
        ]);
    });
};
