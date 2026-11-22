<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Auth, BlueprintManager, Database, DynamicContentService, Security, SiteContext, SiteScopeMode, SiteScopedContentRepository, View};

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
                    'کیت سایت «%s» با موفقیت اعمال شد (%d صفحه آغازین، %d نوع محتوا).',
                    $res['blueprint'],
                    $res['seeded_pages'],
                    count($res['created_types'])
                );
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $currentProfile = 'corporate';
        try {
            if (SiteScopeMode::active($pdo)) {
                $map = (new SiteScopedContentRepository($pdo, SiteContext::id()))->settingsMap();
                $currentProfile = (string) ($map['site_profile'] ?? 'corporate');
            } else {
                $q = $pdo->query("SELECT setting_value FROM cms_settings WHERE setting_key='site_profile' LIMIT 1");
                $currentProfile = (string) ($q->fetchColumn() ?: 'corporate');
            }
        } catch (Throwable) {
        }

        $catalog = BlueprintManager::catalog();
        View::render('blueprints', [
            'title' => 'کیت‌های آماده سایت',
            'user' => $user,
            'catalog' => $catalog,
            'currentProfile' => $currentProfile,
            'message' => $message,
            'error' => $error,
        ]);
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
                    $message = 'نوع محتوای سفارشی با موفقیت ذخیره و در API فعال شد.';
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
        View::render('content-types', [
            'title' => 'انواع محتوا و فیلدهای پویا',
            'user' => $user,
            'types' => $types,
            'message' => $message,
            'error' => $error,
        ]);
    }
}
