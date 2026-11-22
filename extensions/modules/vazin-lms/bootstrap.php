<?php
declare(strict_types=1);

require_once __DIR__ . '/LmsService.php';

use VazinCMS\{Access, ModuleContext, Security, UiLocale, View};
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

    $module->any('/courses', static function () use ($service): void {
        $svc = $service();
        $enrollSuccess = null;
        $enrollError = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $svc->enroll(
                    (int) ($_POST['course_id'] ?? 0),
                    (string) ($_POST['student_name'] ?? ''),
                    (string) ($_POST['student_email'] ?? '')
                );
                $enrollSuccess = 'ثبت‌نام شما در دوره آموزشی با موفقیت انجام شد.';
            } catch (\Throwable $e) {
                $enrollError = $e->getMessage();
            }
        }
        View::renderPublic('courses', [
            'locale' => UiLocale::detect(),
            'meta' => ['title' => 'دوره‌های آموزشی', 'description' => 'آکادمی آموزش آنلاین'],
            'menu' => [],
            'courses' => $svc->listCourses(true, 50),
            'enrollSuccess' => $enrollSuccess,
            'enrollError' => $enrollError,
        ]);
    });

    $module->any('/admin/lms', static function () use ($service): void {
        $user = Access::require('catalog');
        $svc = $service();
        $message = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $action = (string) ($_POST['action'] ?? 'create_course');
                if ($action === 'add_lesson') {
                    $svc->addLesson((int) ($_POST['course_id'] ?? 0), $_POST);
                    $message = 'درس جدید به سرفصل دوره اضافه شد.';
                } else {
                    $svc->createCourse($_POST);
                    $message = 'دوره آموزشی جدید منتشر شد.';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        View::render('lms-admin', [
            'title' => 'مدیریت آموزشگاه (LMS)',
            'user' => $user,
            'courses' => $svc->listCourses(false, 50),
            'message' => $message,
            'error' => $error,
        ]);
    });
};
