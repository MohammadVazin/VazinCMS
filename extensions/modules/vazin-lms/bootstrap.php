<?php
declare(strict_types=1);

require_once __DIR__ . '/LmsService.php';

use VazinCMS\{Access, ModuleContext, Security};
use VazinCMS\Modules\Lms\LmsService;

return static function (ModuleContext $module): void {
    $service = static fn(): LmsService => new LmsService($module->db());
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $module->registerBlock('course-grid', [
        'label' => 'فهرست دوره‌های آموزشی',
        'category' => 'lms',
        'schema' => [
            'limit' => 'int',
            'heading' => 'string',
        ],
    ], static function (array $data) use ($service, $esc): string {
        $limit = max(1, min(24, (int) ($data['limit'] ?? 6)));
        $heading = trim((string) ($data['heading'] ?? 'دوره‌های آموزشی'));
        $courses = $service()->listCourses(true, $limit);
        $html = '<section class="vz-lms-grid"><h2>' . $esc($heading) . '</h2><div class="vz-lms-cards">';
        foreach ($courses as $c) {
            $html .= '<article class="vz-course-card"><h3>' . $esc((string) $c['title']) . '</h3>'
                . '<p>' . $esc((string) $c['summary']) . '</p>'
                . '<small>مدرس: ' . $esc((string) $c['instructor']) . ' | ' . (int) $c['duration_hours'] . ' ساعت</small>'
                . '<a href="/courses?id=' . (int) $c['id'] . '">مشاهده و ثبت‌نام</a></article>';
        }
        return $html . '</div></section>';
    });

    $module->api('GET', 'courses', static fn(array $input, array $query): array => [
        'items' => $service()->listCourses(true, max(1, min(100, (int) ($query['limit'] ?? 25)))),
    ]);

    $module->api('POST', 'enroll', static fn(array $input): array => $service()->enroll(
        (int) ($input['course_id'] ?? 0),
        (string) ($input['student_name'] ?? ''),
        (string) ($input['student_email'] ?? '')
    ));

    $module->get('/courses', static function () use ($service, $esc): void {
        $courses = $service()->listCourses(true, 50);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>آکادمی آموزش آنلاین</title></head><body><main><h1>دوره‌های آموزشی</h1>';
        foreach ($courses as $c) {
            echo '<article><h2>' . $esc((string) $c['title']) . '</h2><p>' . $esc((string) $c['summary']) . '</p></article>';
        }
        echo '</main></body></html>';
    });

    $module->any('/admin/lms', static function () use ($service, $esc): void {
        Access::require('catalog');
        $svc = $service();
        $msg = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $svc->createCourse($_POST);
            $msg = 'دوره آموزشی ذخیره شد.';
        }
        $courses = $svc->listCourses(false, 50);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>مدیریت آموزشگاه</title></head><body><main><h1>مدیریت دوره‌ها (' . count($courses) . ')</h1>';
        if ($msg !== '') echo '<p>' . $esc($msg) . '</p>';
        echo '</main></body></html>';
    });
};
