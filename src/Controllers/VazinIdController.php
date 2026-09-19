<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\{Audit,Auth,Security,VazinIdSettings,View};

final class VazinIdController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner']);
        $error = null;
        $message = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                VazinIdSettings::save($_POST, (int)$user['id']);
                Audit::log('vazin_id.settings_saved', 'تنظیمات اتصال مستقیم Vazin ID ذخیره شد', (int)$user['id']);
                $message = 'تنظیمات Vazin ID ذخیره شد.';
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $settings = VazinIdSettings::current();
        View::render('vazin-id', compact('user','settings','error','message'));
    }
}
