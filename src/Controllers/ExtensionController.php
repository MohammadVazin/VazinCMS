<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use Throwable;
use VazinCMS\{Audit,Auth,ExtensionManager,Security,View};

final class ExtensionController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner']);
        $error = null;
        $message = isset($_GET['saved']) ? 'عملیات افزونه با موفقیت انجام شد.' : null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $action = (string) ($_POST['action'] ?? '');
            $key = strtolower(trim((string) ($_POST['extension_key'] ?? '')));
            try {
                if ($action === 'install') {
                    $row = ExtensionManager::installUploadedFile((array) ($_FILES['package'] ?? []));
                    $key = (string) $row['extension_key'];
                } elseif ($action === 'activate') {
                    ExtensionManager::activate($key);
                } elseif ($action === 'deactivate') {
                    ExtensionManager::deactivate($key);
                } elseif ($action === 'archive') {
                    ExtensionManager::archive($key);
                } else {
                    throw new \InvalidArgumentException('عملیات افزونه معتبر نیست.');
                }
                Audit::log('cms.extension_' . $action, 'وضعیت افزونه تغییر کرد', (int) $user['id'], [
                    'extension_key' => $key,
                ]);
                header('Location: /admin/extensions?saved=1');
                return;
            } catch (Throwable $failure) {
                $error = $failure->getMessage();
            }
        }
        $extensions = ExtensionManager::all();
        View::render('extensions', compact('user', 'extensions', 'error', 'message'));
    }
}
