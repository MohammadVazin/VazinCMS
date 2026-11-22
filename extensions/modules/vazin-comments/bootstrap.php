<?php
declare(strict_types=1);

require_once __DIR__ . '/CommentsService.php';

use VazinCMS\{Access, ModuleContext, Security, View};
use VazinCMS\Modules\Comments\CommentsService;

return static function (ModuleContext $module): void {
    $service = static fn(): CommentsService => new CommentsService($module->db());
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $module->registerBlock('reviews', [
        'label' => 'دیدگاه‌ها و امتیازات کاربران',
        'category' => 'publishing',
        'schema' => [
            'target_type' => 'string',
            'target_key' => 'string:required',
            'heading' => 'string',
        ],
    ], static function (array $data) use ($service, $esc): string {
        $type = (string) ($data['target_type'] ?? 'post');
        $key = (string) ($data['target_key'] ?? '');
        $heading = trim((string) ($data['heading'] ?? 'دیدگاه‌های کاربران'));
        $res = $service()->approvedForTarget($type, $key, 30);
        $avgHtml = $res['average_rating'] !== null ? ' <small>(امتیاز میانگین: ' . $res['average_rating'] . ' از ۵)</small>' : '';
        $html = '<section class="vz-comments-block"><h3>' . $esc($heading) . $avgHtml . '</h3>';
        foreach ($res['items'] as $c) {
            $stars = $c['rating'] !== null ? str_repeat('★', (int) $c['rating']) : '';
            $html .= '<article class="vz-comment-card"><strong>' . $esc((string) $c['author_name']) . '</strong> '
                . ($stars !== '' ? '<span class="vz-stars">' . $stars . '</span>' : '')
                . '<p>' . $esc((string) $c['body']) . '</p></article>';
        }
        return $html . '</section>';
    });

    $module->api('GET', 'list', static fn(array $in, array $q): array => $service()->approvedForTarget(
        (string) ($q['target_type'] ?? 'post'),
        (string) ($q['target_key'] ?? 'home')
    ));

    $module->api('POST', 'post', static fn(array $input): array => $service()->submit(
        $input,
        false,
        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
    ));

    $module->any('/admin/comments', static function () use ($service): void {
        $user = Access::require('content');
        $svc = $service();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $id = (int) ($_POST['comment_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? 'approved');
            if ($id > 0) {
                $svc->moderate($id, $status);
                $message = 'وضعیت دیدگاه به‌روزرسانی شد.';
            }
        }
        View::render('comments-admin', [
            'title' => 'دیدگاه‌ها و امتیازها',
            'user' => $user,
            'comments' => $svc->listAll(100),
            'message' => $message,
        ]);
    });
};
