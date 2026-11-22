<?php
declare(strict_types=1);

namespace VazinCMS\Modules\Comments;

use InvalidArgumentException;
use VazinCMS\{ExtensionRuntime, ModuleDatabase, PageCacheService};

final class CommentsService
{
    private bool $ready = false;

    public function __construct(private ModuleDatabase $db)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) return;
        $this->db->ensureTable('comments', [
            'target_type'  => 'string:40:default=post',
            'target_key'   => 'string:180',
            'author_name'  => 'string:120',
            'author_email' => 'string:180',
            'rating'       => 'int:nullable',
            'body'         => 'text',
            'ip_hash'      => 'string:64',
            'status'       => 'string:20:default=pending',
        ]);
        $this->ready = true;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function submit(array $input, bool $autoApprove = false, string $ip = ''): array
    {
        $this->ensureSchema();
        if (!empty($input['_hp_website'])) {
            throw new InvalidArgumentException('دیدگاه اسپم مسدود شد.');
        }
        $targetType = strtolower(trim((string) ($input['target_type'] ?? 'post')));
        if (!in_array($targetType, ['post', 'page', 'product', 'course'], true)) {
            $targetType = 'post';
        }
        $targetKey = strtolower(trim((string) ($input['target_key'] ?? '')));
        if (preg_match('/^[a-z0-9\x{0600}-\x{06FF}_-]{1,180}$/u', $targetKey) !== 1) {
            throw new InvalidArgumentException('مقصد دیدگاه معتبر نیست.');
        }
        $name = trim((string) ($input['author_name'] ?? ''));
        $email = strtolower(trim((string) ($input['author_email'] ?? '')));
        $body = trim((string) ($input['body'] ?? ''));
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($body) < 3) {
            throw new InvalidArgumentException('نام، ایمیل معتبر و متن دیدگاه الزامی است.');
        }
        $rating = null;
        if (isset($input['rating']) && $input['rating'] !== '' && $input['rating'] !== null) {
            $r = (int) $input['rating'];
            if ($r < 1 || $r > 5) {
                throw new InvalidArgumentException('امتیاز باید بین ۱ تا ۵ ستاره باشد.');
            }
            $rating = $r;
        }
        $status = $autoApprove ? 'approved' : 'pending';
        $id = $this->db->insert('comments', [
            'target_type' => $targetType,
            'target_key' => $targetKey,
            'author_name' => mb_substr($name, 0, 120),
            'author_email' => $email,
            'rating' => $rating,
            'body' => mb_substr($body, 0, 3000),
            'ip_hash' => $ip !== '' ? hash('sha256', $ip) : '',
            'status' => $status,
        ]);
        if ($autoApprove) {
            PageCacheService::invalidateTag('module:vazin-comments');
        }
        ExtensionRuntime::emit('comments.submitted', ['comment_id' => $id, 'target_type' => $targetType, 'target_key' => $targetKey]);
        return $this->db->find('comments', $id) ?? ['id' => $id];
    }

    public function moderate(int $id, string $status): bool
    {
        $this->ensureSchema();
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            throw new InvalidArgumentException('وضعیت دیدگاه معتبر نیست.');
        }
        $ok = $this->db->update('comments', $id, ['status' => $status]);
        PageCacheService::invalidateTag('module:vazin-comments');
        return $ok;
    }

    /**
     * @return array{items:list<array<string,mixed>>,count:int,average_rating:?float}
     */
    public function approvedForTarget(string $targetType, string $targetKey, int $limit = 50): array
    {
        $this->ensureSchema();
        $items = $this->db->where('comments', [
            'target_type' => strtolower(trim($targetType)),
            'target_key' => strtolower(trim($targetKey)),
            'status' => 'approved',
        ], 'id DESC', $limit);

        $rated = array_filter($items, static fn(array $r): bool => $r['rating'] !== null && (int) $r['rating'] > 0);
        $avg = null;
        if ($rated !== []) {
            $sum = array_sum(array_map(static fn(array $r): int => (int) $r['rating'], $rated));
            $avg = round($sum / count($rated), 2);
        }
        return ['items' => $items, 'count' => count($items), 'average_rating' => $avg];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAll(int $limit = 100): array
    {
        $this->ensureSchema();
        return $this->db->where('comments', [], 'id DESC', $limit);
    }
}
