<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use PDO;
use VazinCMS\Auth;
use VazinCMS\Database;
use VazinCMS\View;

/**
 * Read-only CMS landing page. This is deliberately single-site-only until
 * the tenant-scoped query and authorization rollout has independently passed.
 */
final class AdminWorkspaceController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner', 'admin', 'support']);
        // Support can link an explicitly provisioned account to Vazin ID,
        // but must not see or mutate editorial data without content permission.
        if (($user['role'] ?? '') === 'support') {
            View::render('admin-support', ['user' => $user, 'title' => 'پیشخوان پشتیبانی']);
            return;
        }
        $pdo = Database::connection();

        $siteCount = (int) $pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn();
        if ($siteCount !== 1) {
            http_response_code(409);
            View::render('error', [
                'title' => 'پیشخوان نیازمند جداسازی سایت‌هاست',
                'message' => 'داشبورد تجمیعی چندسایتی تا تأیید مجوزها و جداسازی داده‌ها فعال نمی‌شود.',
            ]);
            return;
        }

        $aggregate = $pdo->query(
            "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status='published' THEN 1 ELSE 0 END),0) AS published,
                COALESCE(SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END),0) AS drafts,
                COALESCE(SUM(CASE WHEN content_type='post' THEN 1 ELSE 0 END),0) AS posts
             FROM cms_pages WHERE trash_status='active'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $stats = [
            'total' => (int) ($aggregate['total'] ?? 0),
            'published' => (int) ($aggregate['published'] ?? 0),
            'drafts' => (int) ($aggregate['drafts'] ?? 0),
            'posts' => (int) ($aggregate['posts'] ?? 0),
            'media' => (int) $pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn(),
        ];

        $recent = $pdo->query(
            "SELECT id,title,locale,content_type,status,updated_at
             FROM cms_pages
             WHERE trash_status='active'
             ORDER BY updated_at DESC,id DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC);
        View::render('admin-home', [
            'user' => $user,
            'stats' => $stats,
            'recent' => $recent,
            'title' => 'پیشخوان',
        ]);
    }
}
