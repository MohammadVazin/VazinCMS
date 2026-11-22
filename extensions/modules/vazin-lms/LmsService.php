<?php
declare(strict_types=1);

namespace VazinCMS\Modules\Lms;

use InvalidArgumentException;
use VazinCMS\{ExtensionRuntime, ModuleDatabase, PageCacheService};

final class LmsService
{
    private bool $ready = false;

    public function __construct(private ModuleDatabase $db)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->db->ensureTable('courses', [
            'slug'           => 'string:180',
            'title'          => 'string:255',
            'summary'        => 'text',
            'instructor'     => 'string:120',
            'level'          => 'string:30:default=beginner',
            'duration_hours' => 'int:default=1',
            'price'          => 'decimal',
            'currency'       => 'string:10:default=IRR',
            'status'         => 'string:20:default=published',
        ], [['slug']]);

        $this->db->ensureTable('lessons', [
            'course_id'        => 'bigint',
            'title'            => 'string:255',
            'content'          => 'text',
            'video_url'        => 'string:500',
            'duration_minutes' => 'int:default=10',
            'is_free_preview'  => 'bool',
            'position'         => 'int:default=1',
        ]);

        $this->db->ensureTable('enrollments', [
            'course_id'              => 'bigint',
            'student_name'           => 'string:160',
            'student_email'          => 'string:180',
            'progress_percent'       => 'int:default=0',
            'completed_lessons_json' => 'json',
            'certificate_code'       => 'string:60',
            'status'                 => 'string:20:default=active',
        ], [['course_id', 'student_email']]);

        $this->ready = true;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createCourse(array $input): array
    {
        $this->ensureSchema();
        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) < 2) {
            throw new InvalidArgumentException('عنوان دوره باید حداقل ۲ کاراکتر باشد.');
        }
        $slug = self::slugify((string) ($input['slug'] ?? $title));
        $levelVal = (string) ($input['level'] ?? 'beginner');
        $level = in_array($levelVal, ['beginner', 'intermediate', 'advanced'], true)
            ? $levelVal
            : 'beginner';
        $statusVal = (string) ($input['status'] ?? 'published');
        $id = $this->db->insert('courses', [
            'slug' => $slug,
            'title' => $title,
            'summary' => mb_substr(trim((string) ($input['summary'] ?? '')), 0, 4000),
            'instructor' => mb_substr(trim((string) ($input['instructor'] ?? 'مدرس آکادمی')), 0, 120),
            'level' => $level,
            'duration_hours' => max(1, (int) ($input['duration_hours'] ?? 1)),
            'price' => max(0.0, (float) ($input['price'] ?? 0)),
            'currency' => strtoupper(trim((string) ($input['currency'] ?? 'IRR'))),
            'status' => in_array($statusVal, ['published', 'draft'], true) ? $statusVal : 'published',
        ]);
        PageCacheService::invalidateTag('module:vazin-lms');
        return $this->db->find('courses', $id) ?? ['id' => $id];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function addLesson(int $courseId, array $input): array
    {
        $this->ensureSchema();
        $course = $this->db->find('courses', $courseId);
        if ($course === null) {
            throw new InvalidArgumentException('دوره موردنظر یافت نشد.');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) < 2) {
            throw new InvalidArgumentException('عنوان درس معتبر نیست.');
        }
        $id = $this->db->insert('lessons', [
            'course_id' => $courseId,
            'title' => $title,
            'content' => (string) ($input['content'] ?? ''),
            'video_url' => mb_substr(trim((string) ($input['video_url'] ?? '')), 0, 500),
            'duration_minutes' => max(1, (int) ($input['duration_minutes'] ?? 10)),
            'is_free_preview' => !empty($input['is_free_preview']),
            'position' => max(1, (int) ($input['position'] ?? ($this->db->count('lessons', ['course_id' => $courseId]) + 1))),
        ]);
        return $this->db->find('lessons', $id) ?? ['id' => $id];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCourses(bool $publishedOnly = true, int $limit = 50): array
    {
        $this->ensureSchema();
        return $this->db->where('courses', $publishedOnly ? ['status' => 'published'] : [], 'id DESC', $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLessons(int $courseId): array
    {
        $this->ensureSchema();
        return $this->db->where('lessons', ['course_id' => $courseId], 'position ASC, id ASC', 200);
    }

    /**
     * @return array<string,mixed>
     */
    public function enroll(int $courseId, string $studentName, string $studentEmail): array
    {
        $this->ensureSchema();
        $course = $this->db->find('courses', $courseId);
        if ($course === null || ($course['status'] ?? '') !== 'published') {
            throw new InvalidArgumentException('دوره انتخاب‌شده فعال نیست.');
        }
        $studentName = trim($studentName);
        $studentEmail = strtolower(trim($studentEmail));
        if (mb_strlen($studentName) < 2 || !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('نام و ایمیل معتبر دانشجو الزامی است.');
        }
        $existing = $this->db->findOneBy('enrollments', ['course_id' => $courseId, 'student_email' => $studentEmail]);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->db->insert('enrollments', [
            'course_id' => $courseId,
            'student_name' => $studentName,
            'student_email' => $studentEmail,
            'progress_percent' => 0,
            'completed_lessons_json' => [],
            'certificate_code' => '',
            'status' => 'active',
        ]);
        ExtensionRuntime::emit('lms.student_enrolled', ['enrollment_id' => $id, 'course_id' => $courseId]);
        return $this->db->find('enrollments', $id) ?? ['id' => $id];
    }

    /**
     * @return array<string,mixed>
     */
    public function completeLesson(int $enrollmentId, int $lessonId): array
    {
        $this->ensureSchema();
        $enrollment = $this->db->find('enrollments', $enrollmentId);
        if ($enrollment === null) {
            throw new InvalidArgumentException('ثبت‌نام دانشجو یافت نشد.');
        }
        $lesson = $this->db->find('lessons', $lessonId);
        if ($lesson === null || (int) $lesson['course_id'] !== (int) $enrollment['course_id']) {
            throw new InvalidArgumentException('درس متعلق به این دوره نیست.');
        }
        $done = json_decode((string) ($enrollment['completed_lessons_json'] ?? '[]'), true) ?: [];
        if (!in_array($lessonId, $done, true)) {
            $done[] = $lessonId;
        }
        $totalLessons = max(1, $this->db->count('lessons', ['course_id' => (int) $enrollment['course_id']]));
        $progress = min(100, (int) round((count($done) / $totalLessons) * 100));
        $cert = (string) ($enrollment['certificate_code'] ?? '');
        if ($progress === 100 && $cert === '') {
            $cert = 'CERT-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        }
        $this->db->update('enrollments', $enrollmentId, [
            'completed_lessons_json' => array_values($done),
            'progress_percent' => $progress,
            'certificate_code' => $cert,
            'status' => $progress === 100 ? 'completed' : 'active',
        ]);
        return $this->db->find('enrollments', $enrollmentId) ?? [];
    }

    private static function slugify(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = (string) preg_replace('/[^a-z0-9\x{0600}-\x{06FF}\x{0400}-\x{04FF}-]+/u', '-', $s);
        $s = trim($s, '-');
        return $s !== '' ? mb_substr($s, 0, 175) : ('course-' . bin2hex(random_bytes(3)));
    }
}
