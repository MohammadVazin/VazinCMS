<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

/**
 * Site-scoped repository for the *proposed* 10.31 content schema.
 *
 * This is a non-routed implementation slice. The global SiteIsolationGate
 * MUST continue to reject multi-site HTTP traffic until every module/route,
 * tenant-bound session, site domain, API token and storage path is isolated.
 */
final class SiteScopedContentRepository
{
    private readonly PDO $pdo;
    private readonly int $siteId;

    public function __construct(PDO $pdo, int $siteId)
    {
        if ($siteId < 1) {
            throw new RuntimeException('tenant_site_required');
        }
        $this->pdo = $pdo;
        $this->siteId = $siteId;
        $this->requireMigration();
        $q = $pdo->prepare("SELECT 1 FROM cms_sites WHERE id=:site AND status IN ('active','staging')");
        $q->execute(['site'=>$siteId]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('tenant_site_unavailable');
        }
    }

    public function homePage(string $locale): ?array
    {
        $sql="SELECT * FROM cms_pages WHERE site_id=:site AND locale=:locale " .
            "AND status='published' AND trash_status='active' AND is_home=1 LIMIT 1";
        $q=$this->pdo->prepare($sql);
        $q->execute(['site'=>$this->siteId,'locale'=>$locale]);
        $page=$q->fetch(PDO::FETCH_ASSOC);
        if (is_array($page)) return $page;
        $q=$this->pdo->prepare(
            "SELECT * FROM cms_pages WHERE site_id=:site AND locale=:locale " .
            "AND status='published' AND trash_status='active' AND content_type='page' " .
            "ORDER BY id LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'locale'=>$locale]);
        $page=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($page)?$page:null;
    }

    public function settingsMap(): array
    {
        $q=$this->pdo->prepare(
            'SELECT setting_key,setting_value FROM cms_settings WHERE site_id=:site'
        );
        $q->execute(['site'=>$this->siteId]);
        $out=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $out[(string)$item['setting_key']] = (string)$item['setting_value'];
        }
        return $out;
    }

    public function mediaByStoredName(string $storedName): ?array
    {
        $q=$this->pdo->prepare(
            'SELECT id,mime_type,file_size,stored_name FROM cms_media ' .
            'WHERE site_id=:site AND stored_name=:name LIMIT 1'
        );
        $q->execute(['site'=>$this->siteId,'name'=>$storedName]);
        $result=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($result)?$result:null;
    }

    public function publicPage(string $locale, string $slug): ?array
    {
        $q = $this->pdo->prepare(
            "SELECT * FROM cms_pages WHERE site_id=:site AND locale=:locale " .
            "AND slug=:slug AND status='published' AND trash_status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'locale'=>$locale,'slug'=>$slug]);
        $page = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($page) ? $page : null;
    }

    public function listEditablePages(int $actorId, int $limit=100): array
    {
        $role = $this->role($actorId, ['owner','admin','editor','author']);
        $conditions = "site_id=:site AND trash_status='active'";
        $bind=['site'=>$this->siteId];
        if ($role === 'author') {
            $conditions .= ' AND author_id=:actor';
            $bind['actor']=$actorId;
        }
        $sql = "SELECT id,slug,locale,title,status,author_id FROM cms_pages WHERE $conditions " .
            'ORDER BY id DESC LIMIT '.max(1,min(200,$limit));
        $q=$this->pdo->prepare($sql);
        $q->execute($bind);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createDraft(int $actorId, string $locale, string $slug, string $title, string $body): int
    {
        $this->role($actorId, ['owner','admin','editor','author']);
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,188}$/D', $slug) !== 1
            || preg_match('/^[a-z]{2,3}$/D',$locale) !== 1
            || mb_strlen(trim($title)) < 2) {
            throw new RuntimeException('tenant_invalid_page');
        }
        $sql="INSERT INTO cms_pages(site_id,slug,locale,title,body,status,is_home,author_id) " .
            "VALUES(:site,:slug,:locale,:title,:body,'draft',0,:actor)";
        $q=$this->pdo->prepare($sql);
        $q->execute(['site'=>$this->siteId,'slug'=>$slug,'locale'=>$locale,
            'title'=>$title,'body'=>$body,'actor'=>$actorId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateBody(int $actorId, int $pageId, string $title, string $body): bool
    {
        if ($pageId < 1 || mb_strlen(trim($title)) < 2) {
            throw new RuntimeException('tenant_invalid_page');
        }
        $this->authorizePage($actorId, $pageId);
        $q=$this->pdo->prepare(
            'UPDATE cms_pages SET title=:title,body=:body,updated_at=CURRENT_TIMESTAMP ' .
            'WHERE id=:id AND site_id=:site'
        );
        $q->execute(['title'=>$title,'body'=>$body,'id'=>$pageId,'site'=>$this->siteId]);
        return $q->rowCount()===1;
    }

    public function trashPage(int $actorId, int $pageId): void
    {
        $this->authorizePage($actorId, $pageId);
        $q=$this->pdo->prepare(
            "UPDATE cms_pages SET trash_status='trash',trashed_at=CURRENT_TIMESTAMP," .
            "trashed_by=:actor,is_home=0 WHERE site_id=:site AND id=:id AND trash_status='active'"
        );
        $q->execute(['actor'=>$actorId,'site'=>$this->siteId,'id'=>$pageId]);
        if ($q->rowCount()!==1) {
            throw new RuntimeException('tenant_content_unavailable');
        }
    }

    public function revisions(int $actorId, int $pageId): array
    {
        $this->authorizePage($actorId, $pageId);
        $q=$this->pdo->prepare(
            'SELECT r.id,r.title,r.body,r.created_at FROM cms_page_revisions r ' .
            'JOIN cms_pages p ON p.id=r.page_id AND p.site_id=r.site_id ' .
            'WHERE r.site_id=:site AND p.site_id=:parent AND p.id=:id ' .
            'ORDER BY r.id DESC LIMIT 50'
        );
        $q->execute(['site'=>$this->siteId,'parent'=>$this->siteId,'id'=>$pageId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mediaList(): array
    {
        $q=$this->pdo->prepare(
            'SELECT id,file_name,mime_type,alt_text,stored_name FROM cms_media ' .
            'WHERE site_id=:site ORDER BY id DESC LIMIT 200'
        );
        $q->execute(['site'=>$this->siteId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mediaById(int $mediaId): ?array
    {
        $q=$this->pdo->prepare(
            'SELECT id,file_name,mime_type,alt_text,stored_name FROM cms_media ' .
            'WHERE site_id=:site AND id=:id LIMIT 1'
        );
        $q->execute(['site'=>$this->siteId,'id'=>$mediaId]);
        $result=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($result)?$result:null;
    }

    public function menuItems(string $locale): array
    {
        $q=$this->pdo->prepare(
            'SELECT id,label,url,position FROM cms_menu_items WHERE site_id=:site ' .
            'AND locale=:locale AND is_enabled=1 ORDER BY position,id'
        );
        $q->execute(['site'=>$this->siteId,'locale'=>$locale]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search(string $term, string $locale=''): array
    {
        $term=trim($term);
        if ($term==='') return [];
        $sql='SELECT i.page_id,i.locale,i.content_type,i.title FROM cms_search_index i ' .
            'JOIN cms_pages p ON p.id=i.page_id AND p.site_id=i.site_id ' .
            "WHERE i.site_id=:site AND p.site_id=:parent " .
            "AND p.status='published' AND p.trash_status='active' " .
            'AND (lower(i.title) LIKE :title OR lower(i.search_text) LIKE :content)';
        $args=['site'=>$this->siteId,'parent'=>$this->siteId,
            'title'=>'%'.mb_strtolower($term).'%',
            'content'=>'%'.mb_strtolower($term).'%'];
        if ($locale!=='') {
            $sql.=' AND i.locale=:locale';
            $args['locale']=$locale;
        }
        $sql.=' ORDER BY i.page_id DESC LIMIT 100';
        $q=$this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveRedirect(int $actorId, string $source, string $target, int $statusCode): void
    {
        $this->role($actorId,['owner','admin','editor']);
        if (preg_match('#^/[A-Za-z0-9/_\-.]{1,498}$#D',$source)!==1
            || !in_array($statusCode,[301,302,307,308],true)
            || !(str_starts_with($target,'/') ||
                 (filter_var($target,FILTER_VALIDATE_URL) &&
                  preg_match('#^https://#i',$target)))) {
            throw new RuntimeException('tenant_invalid_redirect');
        }
        // Existing schema has a global UNIQUE(source_path). Do not overwrite
        // a redirect that belongs to another site on unique conflict.
        $q=$this->pdo->prepare(
            'INSERT INTO cms_redirects(site_id,source_path,target_url,status_code,created_by) ' .
            'VALUES(:site,:source,:target,:status,:actor) ' .
            'ON CONFLICT'.SiteUniqueKeys::target($this->pdo,'cms_redirects','source_path').' DO UPDATE SET ' .
            'target_url=excluded.target_url,status_code=excluded.status_code,' .
            'updated_at=CURRENT_TIMESTAMP WHERE cms_redirects.site_id=excluded.site_id'
        );
        $q->execute([
            'site'=>$this->siteId,'source'=>$source,'target'=>$target,
            'status'=>$statusCode,'actor'=>$actorId
        ]);
        if ($q->rowCount()===0) {
            throw new RuntimeException('tenant_redirect_conflict');
        }
    }

    public function redirect(string $path): ?array
    {
        $q=$this->pdo->prepare(
            'SELECT id,target_url,status_code FROM cms_redirects ' .
            'WHERE site_id=:site AND source_path=:path LIMIT 1'
        );
        $q->execute(['site'=>$this->siteId,'path'=>$path]);
        $record=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) return null;
        $update=$this->pdo->prepare(
            'UPDATE cms_redirects SET hits=hits+1,last_hit_at=CURRENT_TIMESTAMP ' .
            'WHERE site_id=:site AND id=:id'
        );
        $update->execute(['site'=>$this->siteId,'id'=>(int)$record['id']]);
        return $record;
    }

    private function authorizePage(int $actorId,int $pageId): array
    {
        $role=$this->role($actorId,['owner','admin','editor','author']);
        $q=$this->pdo->prepare(
            'SELECT id,author_id FROM cms_pages WHERE site_id=:site AND id=:id ' .
            "AND trash_status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'id'=>$pageId]);
        $page=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($page) || ($role==='author' && (int)($page['author_id']??0)!==$actorId)) {
            // Same error for other-site ID and missing ID: no existence oracle.
            throw new RuntimeException('tenant_content_unavailable');
        }
        return $page;
    }

    private function role(int $actorId,array $allowed): string
    {
        if ($actorId<1) throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id ' .
            'JOIN cms_sites s ON s.id=m.site_id ' .
            "WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active' " .
            "AND s.status IN ('active','staging') LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        $role=$q->fetchColumn();
        if (!is_string($role) || !in_array($role,$allowed,true)) {
            throw new RuntimeException('tenant_access_denied');
        }
        return $role;
    }

    private function requireMigration(): void
    {
        $tables=['cms_pages','cms_media','cms_menu_items','cms_page_revisions',
            'cms_search_index','cms_redirects','cms_site_memberships'];
        $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach($tables as $table) {
            if ($table==='cms_site_memberships') continue;
            if ($driver==='sqlite') {
                $q=$this->pdo->query('PRAGMA table_info("'.$table.'")');
                $columns=$q->fetchAll(PDO::FETCH_ASSOC);
                $found=false;
                foreach($columns as $column) {
                    if (($column['name']??'')==='site_id') {$found=true;break;}
                }
            } elseif ($driver==='pgsql') {
                $q=$this->pdo->prepare(
                    "SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() " .
                    "AND table_name=:name AND column_name='site_id'"
                );
                $q->execute(['name'=>$table]);
                $found=(bool)$q->fetchColumn();
            } else {
                throw new RuntimeException('tenant_driver_unsupported');
            }
            if (!$found) {
                throw new RuntimeException('tenant_schema_unavailable');
            }
        }
    }
}
