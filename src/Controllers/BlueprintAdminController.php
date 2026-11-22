<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Auth, BlueprintManager, Database, DynamicContentService, Security};

final class BlueprintAdminController
{
    public function blueprints(): void
    {
        $user = Auth::requireUser(['owner', 'admin']);
        $pdo = Database::connection();
        $message = '';
        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $key = (string) ($_POST['blueprint'] ?? '');
                $locale = (string) ($_POST['locale'] ?? 'fa');
                $res = BlueprintManager::apply($pdo, $key, $locale, (int) ($user['id'] ?? 1));
                $message = sprintf(
                    'کیت سایت «%s» با موفقیت اعمال شد (%d صفحه نمونه، %d ماژول فعال).',
                    $res['blueprint'],
                    $res['seeded_pages'],
                    count($res['activated_modules'])
                );
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $catalog = BlueprintManager::catalog();
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = Security::csrfToken();

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کیت‌های آماده سایت | VazinCMS</title></head><body><main class="cms-admin-container"><h1>کیت‌های آماده راه‌اندازی سایت (Site Blueprints)</h1>';
        if ($message !== '') echo '<p class="notice-ok">' . $esc($message) . '</p>';
        if ($error !== '') echo '<p class="notice-err">' . $esc($error) . '</p>';
        echo '<div class="vz-blueprint-grid">';
        foreach ($catalog as $bp) {
            echo '<article class="vz-blueprint-card"><h2>' . $esc((string) $bp['name']) . '</h2>'
                . '<p>' . $esc((string) $bp['description']) . '</p>'
                . '<small>ماژول‌ها: ' . $esc(implode(', ', $bp['modules'])) . '</small>'
                . '<form method="post"><input type="hidden" name="_csrf" value="' . $esc($csrf) . '">'
                . '<input type="hidden" name="blueprint" value="' . $esc((string) $bp['key']) . '">'
                . '<button type="submit">اعمال کیت روی سایت</button></form></article>';
        }
        echo '</div><p><a href="/admin">بازگشت به پیشخوان</a></p></main></body></html>';
    }

    public function contentTypes(): void
    {
        $user = Auth::requireUser(['owner', 'admin']);
        $pdo = Database::connection();
        $message = '';
        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $action = (string) ($_POST['action'] ?? 'define_type');
                if ($action === 'define_type') {
                    $fieldsRaw = json_decode((string) ($_POST['fields_json'] ?? '[]'), true);
                    DynamicContentService::defineType($pdo, (string) ($_POST['type_key'] ?? ''), [
                        'label' => (string) ($_POST['label'] ?? ''),
                        'plural_label' => (string) ($_POST['plural_label'] ?? ''),
                        'description' => (string) ($_POST['description'] ?? ''),
                        'fields' => is_array($fieldsRaw) ? $fieldsRaw : [],
                    ]);
                    $message = 'نوع محتوای سفارشی ذخیره شد.';
                } elseif ($action === 'save_entry') {
                    DynamicContentService::saveEntry(
                        $pdo,
                        (string) ($_POST['type_key'] ?? ''),
                        $_POST,
                        (int) ($user['id'] ?? 1)
                    );
                    $message = 'رکورد محتوای پویا ذخیره شد.';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $types = DynamicContentService::allTypes($pdo);
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>انواع محتوا و فیلدهای پویا | VazinCMS</title></head><body><main class="cms-admin-container"><h1>موتور انواع محتوا و فیلدهای پویا (' . count($types) . ')</h1>';
        if ($message !== '') echo '<p class="notice-ok">' . $esc($message) . '</p>';
        if ($error !== '') echo '<p class="notice-err">' . $esc($error) . '</p>';
        echo '<ul>';
        foreach ($types as $t) {
            echo '<li><strong>' . $esc((string) $t['label']) . '</strong> (<code>' . $esc((string) $t['type_key']) . '</code>) — فیلدها: ' . count($t['fields'] ?? []) . '</li>';
        }
        echo '</ul><p><a href="/admin">بازگشت به پیشخوان</a></p></main></body></html>';
    }
}
