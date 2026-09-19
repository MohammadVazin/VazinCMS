<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\{Audit,Auth,BlockEditor,CacheStore,ContentMigrationService,Database,ExtensionRuntime,SearchIndex,Security,View};

final class ContentMigrationController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner','admin']);
        $error = null; $message = null; $preview = $_SESSION['content_migration_preview'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                if (($_POST['action'] ?? '') === 'commit') {
                    $message = $this->commit($user, $preview);
                    unset($_SESSION['content_migration_preview']); $preview = null;
                } else {
                    $file = $_FILES['migration_file'] ?? null;
                    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
                        throw new \InvalidArgumentException('فایل خروجی را انتخاب کنید.');
                    }
                    $preview = ContentMigrationService::preview((string)$file['name'], (string)file_get_contents((string)$file['tmp_name']));
                    $_SESSION['content_migration_preview'] = $preview + ['created_at' => time()];
                }
            } catch (\Throwable $e) { $error = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'انتقال محتوا انجام نشد.'; }
        }
        View::render('content-migration', compact('user','error','message','preview'));
    }

    private function commit(array $user, mixed $preview): string
    {
        if (!is_array($preview) || !is_array($preview['items'] ?? null) || (time() - (int)($preview['created_at'] ?? 0)) > 1800) {
            throw new \InvalidArgumentException('پیش‌نمایش منقضی شده است؛ فایل را دوباره بارگذاری کنید.');
        }
        $pdo = Database::connection(); $provider = (string)$preview['provider']; $created = 0; $skipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($preview['items'] as $item) {
                $known = $pdo->prepare('SELECT id FROM cms_pages WHERE source_provider=:provider AND source_ref=:ref LIMIT 1');
                $known->execute(['provider'=>$provider,'ref'=>$item['source_ref']]);
                if ($known->fetchColumn()) { $skipped++; continue; }
                $slug = $this->availableSlug($pdo, (string)$item['slug'], (string)$item['locale']);
                $body = (string)$item['body']; $blocks = BlockEditor::encode(BlockEditor::legacy($body));
                $insert = $pdo->prepare('INSERT INTO cms_pages(slug,locale,title,body,status,is_home,author_id,content_type,robots_index,robots_follow,schema_type,block_schema_version,block_document,source_provider,source_ref) VALUES(:slug,:locale,:title,:body,\'draft\',0,:author,:type,0,0,:schema,1,:blocks,:provider,:ref)');
                $insert->execute(['slug'=>$slug,'locale'=>$item['locale'],'title'=>$item['title'],'body'=>$body,'author'=>$user['id'],'type'=>$item['content_type'],'schema'=>$item['content_type']==='post'?'Article':'WebPage','blocks'=>$blocks,'provider'=>$provider,'ref'=>$item['source_ref']]);
                $pageId = (int)$pdo->lastInsertId(); SearchIndex::refreshPage($pdo, $pageId); ExtensionRuntime::emit('content.imported', ['page_id'=>$pageId,'actor_id'=>(int)$user['id'],'provider'=>$provider]); $created++;
            }
            $pdo->commit();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        CacheStore::invalidateTag('content');
        Audit::log('cms.content_imported','محتوای خارجی به پیش‌نویس منتقل شد',(int)$user['id'],['provider'=>$provider,'created'=>$created,'skipped'=>$skipped]);
        return $created . ' پیش‌نویس وارد شد' . ($skipped ? '؛ ' . $skipped . ' مورد تکراری رد شد.' : '.');
    }

    private function availableSlug($pdo, string $base, string $locale): string
    {
        $base = substr($base, 0, 180); $slug = $base; $number = 2;
        $check = $pdo->prepare('SELECT 1 FROM cms_pages WHERE slug=:slug AND locale=:locale LIMIT 1');
        while (true) { $check->execute(['slug'=>$slug,'locale'=>$locale]); if (!$check->fetchColumn()) return $slug; $slug = substr($base, 0, 185) . '-' . $number++; }
    }
}
