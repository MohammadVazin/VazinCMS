<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use VazinCMS\{Audit,Auth,BlockEditor,CacheStore,ContentMigrationService,ContentModel,Database,ExtensionRuntime,MediaArchiveImporter,RedirectManager,SearchIndex,Security,View};

final class ContentMigrationController
{
    public function index(): void
    {
        $user = Auth::requireUser(['owner','admin']);
        $error = null; $message = null; $preview = $_SESSION['content_migration_preview'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            try {
                $action = (string)($_POST['action'] ?? 'preview');
                if ($action === 'commit') {
                    $message = $this->commit($user, $preview);
                    unset($_SESSION['content_migration_preview']); $preview = null;
                } elseif ($action === 'media') {
                    $file = $_FILES['media_archive'] ?? null;
                    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? '')) || !str_ends_with(strtolower((string)$file['name']), '.zip')) throw new \InvalidArgumentException('فقط فایل ZIP بارگذاری‌شده پذیرفته می‌شود.');
                    $stats = MediaArchiveImporter::import((string)$file['tmp_name'], Database::connection(), (int)$user['id']);
                    CacheStore::invalidateTag('media');
                    Audit::log('cms.media_archive_imported','رسانه‌های آرشیوی وارد کتابخانه شد',(int)$user['id'],$stats);
                    $message = $stats['imported'].' رسانه وارد شد؛ '.$stats['duplicates'].' تکراری و '.$stats['skipped'].' فایل نامعتبر رد شد.';
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
                $featured = $this->resolveLocalMedia($pdo, (string)($item['featured_source'] ?? ''));
                $insert = $pdo->prepare('INSERT INTO cms_pages(slug,locale,title,body,status,is_home,author_id,featured_image,content_type,robots_index,robots_follow,schema_type,block_schema_version,block_document,source_provider,source_ref) VALUES(:slug,:locale,:title,:body,\'draft\',0,:author,:featured,:type,0,0,:schema,1,:blocks,:provider,:ref)');
                $insert->execute(['slug'=>$slug,'locale'=>$item['locale'],'title'=>$item['title'],'body'=>$body,'author'=>$user['id'],'featured'=>$featured,'type'=>$item['content_type'],'schema'=>$item['content_type']==='post'?'Article':'WebPage','blocks'=>$blocks,'provider'=>$provider,'ref'=>$item['source_ref']]);
                $pageId = (int)$pdo->lastInsertId();
                $this->syncTerms($pdo, $pageId, $item['terms'] ?? []);
                $sourcePath = (string)($item['source_path'] ?? '');
                if ($sourcePath !== '') RedirectManager::save($pdo, $sourcePath, '/'.$item['locale'].'/page/'.$slug, 301, (int)$user['id']);
                SearchIndex::refreshPage($pdo, $pageId); ExtensionRuntime::emit('content.imported', ['page_id'=>$pageId,'actor_id'=>(int)$user['id'],'provider'=>$provider]); $created++;
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

    private function syncTerms($pdo, int $pageId, mixed $terms): void
    {
        if (!is_array($terms)) return;
        $ids = [];
        foreach ($terms as $term) {
            if (!is_array($term)) continue;
            $taxonomy = (string)($term['taxonomy'] ?? '') === 'tag' ? 'tag' : 'category';
            $taxonomyId = $pdo->prepare('SELECT id FROM cms_taxonomies WHERE taxonomy_key=:key');
            $taxonomyId->execute(['key'=>$taxonomy]); $taxId = $taxonomyId->fetchColumn();
            if (!$taxId) continue;
            $find = $pdo->prepare('SELECT id FROM cms_terms WHERE taxonomy_id=:tax AND slug=:slug');
            $find->execute(['tax'=>$taxId,'slug'=>(string)$term['slug']]); $termId = $find->fetchColumn();
            if (!$termId) { $add = $pdo->prepare("INSERT INTO cms_terms(taxonomy_id,slug,name,description) VALUES(:tax,:slug,:name,'')"); $add->execute(['tax'=>$taxId,'slug'=>$term['slug'],'name'=>$term['name']]); $termId = $pdo->lastInsertId(); }
            if ($termId) $ids[] = (int)$termId;
        }
        if ($ids) ContentModel::assignTerms($pdo, $pageId, $ids);
    }

    private function resolveLocalMedia($pdo, string $source): string
    {
        $path = parse_url($source, PHP_URL_PATH); $name = is_string($path) ? basename($path) : '';
        if ($name === '' || strlen($name) > 255) return '';
        $query = $pdo->query("SELECT stored_name,metadata_json FROM cms_media WHERE metadata_json<>'' ORDER BY id DESC LIMIT 2000");
        foreach ($query->fetchAll() as $media) {
            $metadata = json_decode((string)$media['metadata_json'], true);
            if (is_array($metadata) && isset($metadata['original_path']) && hash_equals(strtolower($name), strtolower(basename((string)$metadata['original_path'])))) return '/uploads/' . (string)$media['stored_name'];
        }
        return '';
    }
}
