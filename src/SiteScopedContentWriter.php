<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Content write side of the candidate tenant rollout.
 *
 * Every mutation requires an ACTIVE user with an explicit membership on the
 * selected site. This class is not an authorization to enable multiple
 * tenants: SiteIsolationGate must remain in force for HTTP entrypoints.
 */
final class SiteScopedContentWriter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ) {
        if ($siteId < 1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_schema_unavailable');
        }
        $q=$pdo->prepare("SELECT 1 FROM cms_sites WHERE id=:site AND status IN('active','staging')");
        $q->execute(['site'=>$siteId]);
        if (!$q->fetchColumn()) throw new RuntimeException('tenant_site_unavailable');
    }

    public function page(int $actorId,int $pageId,bool $includeTrash=false): array
    {
        $role=$this->authorize($actorId,['owner','admin','editor','author']);
        $q=$this->pdo->prepare(
            "SELECT * FROM cms_pages WHERE site_id=:site AND id=:id".
            ($includeTrash?'':" AND trash_status='active'").' LIMIT 1'
        );
        $q->execute(['site'=>$this->siteId,'id'=>$pageId]);
        $page=$q->fetch(PDO::FETCH_ASSOC);
        if (!$page || ($role==='author' && (int)($page['author_id']??0)!==$actorId)) {
            // Neither an unauthorized site ID nor an unknown row is disclosed.
            throw new RuntimeException('tenant_content_unavailable');
        }
        return $page;
    }

    public function pages(int $actorId,int $limit=200): array
    {
        $role=$this->authorize($actorId,['owner','admin','editor','author']);
        $sql="SELECT * FROM cms_pages WHERE site_id=:site AND trash_status='active'";
        $args=['site'=>$this->siteId];
        if ($role==='author') {
            $sql.=' AND author_id=:actor';$args['actor']=$actorId;
        }
        $sql.=' ORDER BY locale,is_home DESC,id DESC LIMIT '.max(1,min(200,$limit));
        $q=$this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Called with already validated editor fields; also validates at boundary. */
    public function savePage(int $actorId,int $pageId,array $fields): int
    {
        $this->authorize($actorId,['owner','admin','editor','author']);
        $slug=(string)($fields['slug']??'');
        $locale=(string)($fields['locale']??'');
        $title=trim((string)($fields['title']??''));
        $status=(string)($fields['status']??'draft');
        $type=(string)($fields['content_type']??'page');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,188}$/D',$slug)!==1 ||
            preg_match('/^(fa|ar|en|ru|tr|hy|kk|tg|zh)$/D',$locale)!==1 ||
            mb_strlen($title)<2 ||
            !in_array($status,['draft','published'],true) ||
            !in_array($type,['page','post'],true)) {
            throw new InvalidArgumentException('tenant_invalid_content');
        }
        $home=$type==='post'?0:(!empty($fields['home'])?1:0);
        $allowed=[
            'slug','locale','title','body','status','home',
            'meta_title','meta_description','canonical_url','featured_image',
            'content_type','published_at','robots_index','robots_follow',
            'og_title','og_description','schema_type',
            'block_schema_version','block_document'
        ];
        $params=[];
        foreach ($allowed as $field) {
            $params[$field]=$fields[$field]??match($field) {
                'body','meta_title','meta_description','canonical_url','featured_image',
                'og_title','og_description','block_document'=>'',
                'published_at'=>null,
                'robots_index','robots_follow'=>1,
                'block_schema_version'=>1,
                'schema_type'=>'WebPage',
                default=>null
            };
        }
        $params['slug']=$slug;$params['locale']=$locale;$params['title']=$title;
        $params['content_type']=$type;$params['status']=$status;$params['home']=$home;
        if (!in_array((string)$params['schema_type'],
              ['Article','NewsArticle','BlogPosting','WebPage'],true)) {
            throw new InvalidArgumentException('tenant_invalid_schema');
        }
        if ($pageId>0) {
            $old=$this->page($actorId,$pageId);
        } else {
            $old=null;
        }
        $ownedTransaction=$this->begin();
        try {
            if ($home) {
                $q=$this->pdo->prepare(
                    'UPDATE cms_pages SET is_home=0 WHERE site_id=:site AND locale=:locale'
                );
                $q->execute(['site'=>$this->siteId,'locale'=>$locale]);
            }
            if ($pageId>0) {
                $this->saveRevisionRow($actorId,$old,'manual');
                $params['site']=$this->siteId;$params['id']=$pageId;
                $assignments=[];
                foreach($allowed as $col)$assignments[]=($col==='home'?'is_home=:home':"$col=:$col");
                $q=$this->pdo->prepare(
                    'UPDATE cms_pages SET '.implode(',',$assignments).
                    ',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND site_id=:site'
                );
                $q->execute($params);
                if ($q->rowCount()!==1) throw new RuntimeException('tenant_content_unavailable');
            } else {
                $params['site']=$this->siteId;$params['actor']=$actorId;
                $cols=array_values(array_diff($allowed,['home']));
                $cols[]='site_id';$cols[]='author_id';$cols[]='is_home';
                $values=array_map(static fn(string $c)=>match($c) {
                    'site_id'=>':site','author_id'=>':actor','is_home'=>':home',
                    default=>':'.$c
                },$cols);
                $q=$this->pdo->prepare(
                    'INSERT INTO cms_pages('.implode(',',$cols).') VALUES('.implode(',',$values).')'
                );
                $q->execute($params);
                $pageId=(int)$this->pdo->lastInsertId();
            }
            $this->commit($ownedTransaction);
            return $pageId;
        } catch(Throwable $e) {
            $this->rollback($ownedTransaction);
            throw $e;
        }
    }

    public function autosave(int $actorId,int $pageId,string $title,string $body,array $metadata=[]): array
    {
        $this->page($actorId,$pageId);
        $hash=hash('sha256',$title."\0".$body."\0".
            json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $args=[
            'site'=>$this->siteId,'page'=>$pageId,'actor'=>$actorId,
            'hash'=>$hash,'title'=>$title,'body'=>$body,
            'meta'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ];
        $q=$this->pdo->prepare(
            'INSERT INTO cms_autosaves(site_id,page_id,actor_id,content_hash,title,body,metadata_json,saved_at) '.
            'VALUES(:site,:page,:actor,:hash,:title,:body,:meta,CURRENT_TIMESTAMP) '.
            'ON CONFLICT(page_id,actor_id) DO UPDATE SET content_hash=excluded.content_hash,'.
            'title=excluded.title,body=excluded.body,metadata_json=excluded.metadata_json,'.
            'saved_at=CURRENT_TIMESTAMP WHERE cms_autosaves.site_id=excluded.site_id'
        );
        $q->execute($args);
        if ($q->rowCount()===0) throw new RuntimeException('tenant_autosave_conflict');
        return ['saved'=>true,'hash'=>$hash];
    }

    public function trash(int $actorId,int $pageId): void
    {
        $page=$this->page($actorId,$pageId);
        $owned=$this->begin();
        try {
            $this->saveRevisionRow($actorId,$page,'pre-trash');
            $q=$this->pdo->prepare(
                "UPDATE cms_pages SET trash_status='trash',trashed_at=CURRENT_TIMESTAMP,".
                "trashed_by=:actor,is_home=0,updated_at=CURRENT_TIMESTAMP ".
                "WHERE site_id=:site AND id=:id AND trash_status='active'"
            );
            $q->execute(['actor'=>$actorId,'site'=>$this->siteId,'id'=>$pageId]);
            if ($q->rowCount()!==1) throw new RuntimeException('tenant_content_unavailable');
            $this->commit($owned);
        } catch(Throwable $e) {
            $this->rollback($owned);throw $e;
        }
    }

    public function restoreTrash(int $actorId,int $pageId): void
    {
        $page=$this->page($actorId,$pageId,true);
        if (($page['trash_status']??'')!=='trash') {
            throw new RuntimeException('tenant_content_unavailable');
        }
        $q=$this->pdo->prepare(
            "UPDATE cms_pages SET trash_status='active',trashed_at=NULL,trashed_by=NULL,".
            "updated_at=CURRENT_TIMESTAMP WHERE site_id=:site AND id=:id AND trash_status='trash'"
        );
        $q->execute(['site'=>$this->siteId,'id'=>$pageId]);
        if ($q->rowCount()!==1) throw new RuntimeException('tenant_content_unavailable');
    }

    public function revisions(int $actorId,int $limit=100): array
    {
        $role=$this->authorize($actorId,['owner','admin','editor','author']);
        $sql='SELECT r.*,p.title AS page_title,p.locale FROM cms_page_revisions r '.
            'JOIN cms_pages p ON p.id=r.page_id AND p.site_id=r.site_id '.
            'WHERE r.site_id=:site';
        $params=['site'=>$this->siteId];
        if($role==='author'){$sql.=' AND p.author_id=:actor';$params['actor']=$actorId;}
        $sql.=' ORDER BY r.id DESC LIMIT '.max(1,min(200,$limit));
        $q=$this->pdo->prepare($sql);$q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function restoreRevision(int $actorId,int $revisionId,?string $expectedBase=null): void
    {
        try{
            (new ContentRevision($this->pdo,$this->siteId))->restore($actorId,$revisionId,$expectedBase);
        }catch(RuntimeException $error){
            if($error->getMessage()==='revision_unavailable')throw new RuntimeException('tenant_content_unavailable');
            if($error->getMessage()==='revision_access_denied')throw new RuntimeException('tenant_access_denied');
            throw $error;
        }
    }

    public function saveMenu(int $actorId,string $locale,string $label,string $url,int $position=0): int
    {
        $this->authorize($actorId,['owner','admin','editor']);
        if (preg_match('/^(fa|ar|en|ru|tr|hy|kk|tg|zh)$/D',$locale)!==1 ||
            mb_strlen(trim($label))<1 ||
            !(str_starts_with($url,'/') && !str_starts_with($url,'//') ||
              (filter_var($url,FILTER_VALIDATE_URL) && str_starts_with($url,'https://')))) {
            throw new InvalidArgumentException('tenant_invalid_menu');
        }
        $q=$this->pdo->prepare(
            'INSERT INTO cms_menu_items(site_id,locale,label,url,position,is_enabled) '.
            'VALUES(:site,:locale,:label,:url,:position,1)'
        );
        $q->execute(['site'=>$this->siteId,'locale'=>$locale,'label'=>$label,
                     'url'=>$url,'position'=>max(0,$position)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteMenu(int $actorId,int $id): void
    {
        $this->authorize($actorId,['owner','admin','editor']);
        $q=$this->pdo->prepare('DELETE FROM cms_menu_items WHERE site_id=:site AND id=:id');
        $q->execute(['site'=>$this->siteId,'id'=>$id]);
        if($q->rowCount()!==1)throw new RuntimeException('tenant_content_unavailable');
    }

    /** Update of existing keys is guarded against global-unique collision with another site. */
    public function saveSettings(int $actorId,array $settings): void
    {
        $this->authorize($actorId,['owner','admin']);
        $allowed=[
            'site_name','site_tagline','site_description','logo_url','default_locale',
            'contact_email','contact_phone','contact_address','site_profile',
            'primary_color','hero_title','hero_text','hero_badge','primary_cta_label',
            'primary_cta_url','secondary_cta_label','secondary_cta_url','stats_json',
            'features_json','steps_json','faq_json','social_telegram',
            'social_instagram','social_youtube','footer_text','enabled_locales'
        ];
        foreach($settings as $k=>$v) {
            if(!in_array($k,$allowed,true) || !is_string($v) || strlen($v)>65535) {
                throw new InvalidArgumentException('tenant_invalid_setting');
            }
        }
        $owned=$this->begin();
        try{
            $q=$this->pdo->prepare(
                'INSERT INTO cms_settings(site_id,setting_key,setting_value) '.
                'VALUES(:site,:key,:value) ON CONFLICT'.SiteUniqueKeys::target($this->pdo,'cms_settings','setting_key').' DO UPDATE SET '.
                'setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP '.
                'WHERE cms_settings.site_id=excluded.site_id'
            );
            foreach($settings as $key=>$value){
                $q->execute(['site'=>$this->siteId,'key'=>$key,'value'=>$value]);
                if($q->rowCount()!==1)throw new RuntimeException('tenant_setting_conflict');
            }
            $this->commit($owned);
        }catch(Throwable $e){$this->rollback($owned);throw $e;}
    }

    public function assertMediaWrite(int $actorId): void
    {
        $this->authorize($actorId,['owner','admin','editor']);
    }

    public function menus(int $actorId): array
    {
        $this->authorize($actorId,['owner','admin','editor']);
        $q=$this->pdo->prepare(
            'SELECT * FROM cms_menu_items WHERE site_id=:site ORDER BY locale,position,id'
        );
        $q->execute(['site'=>$this->siteId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function media(int $actorId,int $limit=200): array
    {
        $this->authorize($actorId,['owner','admin','editor','author']);
        $q=$this->pdo->prepare(
            'SELECT * FROM cms_media WHERE site_id=:site ORDER BY id DESC LIMIT '.max(1,min(200,$limit))
        );
        $q->execute(['site'=>$this->siteId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mediaPicker(int $actorId): array
    {
        $this->authorize($actorId,['owner','admin','editor','author']);
        $q=$this->pdo->prepare(
            'SELECT m.*, (SELECT d.stored_name FROM cms_media_derivatives d '.
            'WHERE d.media_id=m.id AND d.site_id=m.site_id '.
            'ORDER BY d.width DESC LIMIT 1) AS derivative_name '.
            'FROM cms_media m WHERE m.site_id=:site ORDER BY m.id DESC LIMIT 200'
        );
        $q->execute(['site'=>$this->siteId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The scoped filesystem move/delete is intentionally handled separately.
     * Never call the legacy unscoped deletion path from this service.
     */
    public function mediaRow(int $actorId,int $id): array
    {
        $this->authorize($actorId,['owner','admin','editor']);
        $q=$this->pdo->prepare('SELECT * FROM cms_media WHERE site_id=:site AND id=:id');
        $q->execute(['site'=>$this->siteId,'id'=>$id]);
        $r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new RuntimeException('tenant_content_unavailable');
        return $r;
    }

    private function saveRevisionRow(int $actorId,array $row,string $kind): void
    {
        ContentRevision::record($this->pdo,(int)$row['id'],$actorId,$kind,$row,$this->siteId);
    }

    private function authorize(int $actorId,array $roles): string
    {
        if($actorId<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m '.
            'JOIN users u ON u.id=m.user_id '.
            'WHERE m.site_id=:site AND m.user_id=:actor AND u.status=:status LIMIT 1'
        );
        $q->execute(['site'=>$this->siteId,'actor'=>$actorId,'status'=>'active']);
        $role=$q->fetchColumn();
        if(!is_string($role)||!in_array($role,$roles,true)) {
            throw new RuntimeException('tenant_access_denied');
        }
        return $role;
    }

    private function begin(): bool
    {
        if($this->pdo->inTransaction())return false;
        if(!$this->pdo->beginTransaction())throw new RuntimeException('tenant_transaction_failed');
        return true;
    }
    private function commit(bool $owned): void
    {
        if($owned)$this->pdo->commit();
    }
    private function rollback(bool $owned): void
    {
        if($owned && $this->pdo->inTransaction())$this->pdo->rollBack();
    }
}
