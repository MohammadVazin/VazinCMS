<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Candidate-only site-scoped editorial transitions and local publication.
 *
 * Manual call must specify explicit siteId and actorId. It NEVER calls
 * social publishing/webhooks, Telegram, email or legacy Scheduler. Do not
 * wire into automatic workers until site-bound job+token isolation is audited.
 */
final class SiteScopedEditorialWorkflow
{
    private const TRANSITIONS=[
        'draft'=>['in_review','published'],
        'in_review'=>['approved','rejected','draft'],
        'approved'=>['scheduled','published','draft'],
        'scheduled'=>['published','draft'],
        'rejected'=>['draft','in_review'],
        'published'=>['draft']
    ];
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $siteId
    ) {
        if($siteId<1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_editorial_schema_unavailable');
        }
        $q=$pdo->prepare("SELECT 1 FROM cms_sites WHERE id=:site AND status IN('active','staging')");
        $q->execute(['site'=>$siteId]);
        if(!$q->fetchColumn())throw new RuntimeException('tenant_site_unavailable');
        try {
            $pdo->query('SELECT id,site_id,page_id,actor_id FROM cms_site_editorial_events WHERE 1=0');
        }catch(Throwable){
            throw new RuntimeException('tenant_editorial_schema_unavailable');
        }
    }

    public function transition(int $actorId,int $pageId,string $next,string $note=''): void
    {
        $this->authorize($actorId);
        $q=$this->pdo->prepare(
            'SELECT id,editorial_state,published_at,trash_status FROM cms_pages ' .
            "WHERE site_id=:site AND id=:page AND trash_status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'page'=>$pageId]);
        $page=$q->fetch(PDO::FETCH_ASSOC);
        if (!$page)throw new RuntimeException('tenant_content_unavailable');
        $from=(string)$page['editorial_state'];
        if(!in_array($next,self::TRANSITIONS[$from]??[],true)) {
            throw new RuntimeException('tenant_editorial_transition_denied');
        }
        if($next==='scheduled') {
            $timestamp=strtotime((string)($page['published_at']??''));
            if(!$timestamp || $timestamp<=time()) {
                throw new RuntimeException('tenant_editorial_schedule_invalid');
            }
        }
        $this->begin();
        try {
            $published=$next==='published';
            $q=$this->pdo->prepare(
                'UPDATE cms_pages SET editorial_state=:next,' .
                'status=CASE WHEN :published=1 THEN \'published\' ELSE \'draft\' END,' .
                'review_requested_at=CASE WHEN :review=1 THEN CURRENT_TIMESTAMP ' .
                'ELSE review_requested_at END,' .
                'reviewed_at=CASE WHEN :judged=1 THEN CURRENT_TIMESTAMP ELSE reviewed_at END,' .
                'reviewed_by=CASE WHEN :judged2=1 THEN :actor ELSE reviewed_by END,' .
                'updated_at=CURRENT_TIMESTAMP ' .
                'WHERE site_id=:site AND id=:page AND editorial_state=:from ' .
                "AND trash_status='active'"
            );
            $judged=in_array($next,['approved','rejected'],true)?1:0;
            $q->execute([
                'next'=>$next,'published'=>$published?1:0,
                'review'=>$next==='in_review'?1:0,'judged'=>$judged,
                'judged2'=>$judged,'actor'=>$actorId,'site'=>$this->siteId,
                'page'=>$pageId,'from'=>$from
            ]);
            if($q->rowCount()!==1)throw new RuntimeException('tenant_editorial_conflict');
            $this->event($actorId,$pageId,$from,$next,$note);
            $this->refreshSearchIndex($pageId);
            $this->pdo->commit();
        }catch(Throwable $e){
            $this->rollback();throw $e;
        }
    }

    public function due(int $actorId,int $limit=100): array
    {
        $this->authorize($actorId);
        $q=$this->pdo->prepare(
            'SELECT id FROM cms_pages WHERE site_id=:site ' .
            "AND editorial_state='scheduled' AND status='draft' " .
            "AND published_at IS NOT NULL AND published_at<=CURRENT_TIMESTAMP " .
            "AND trash_status='active' ORDER BY published_at,id LIMIT ".
            max(1,min(200,$limit))
        );
        $q->execute(['site'=>$this->siteId]);
        return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function publishDue(int $actorId,int $limit=100,?callable $fence=null): array
    {
        // This is an explicitly called LOCAL publication operation only.
        // Automatic scheduler and all external publications stay disabled.
        $this->authorize($actorId);
        $ids=$this->due($actorId,$limit);
        $changed=0;$conflicts=0;
        foreach($ids as $pageId) {
            $this->begin();
            try{
                // When a durable claim exists, acquire its conditional
                // write fence inside THIS page's transaction before mutating.
                if($fence!==null)$fence();
                $q=$this->pdo->prepare(
                    'UPDATE cms_pages SET editorial_state=\'published\',' .
                    "status='published',updated_at=CURRENT_TIMESTAMP " .
                    'WHERE site_id=:site AND id=:id ' .
                    "AND editorial_state='scheduled' AND status='draft' " .
                    "AND published_at<=CURRENT_TIMESTAMP AND trash_status='active'"
                );
                $q->execute(['site'=>$this->siteId,'id'=>$pageId]);
                if($q->rowCount()!==1) {
                    $this->pdo->rollBack();
                    $conflicts++;
                    continue;
                }
                $this->event($actorId,$pageId,'scheduled','published',
                    'local site-scoped scheduled publish');
                $this->refreshSearchIndex($pageId);
                $this->pdo->commit();
                $changed++;
            }catch(Throwable $e){
                $this->rollback();throw $e;
            }
        }
        return ['published'=>$changed,'conflicts'=>$conflicts,
                'external_delivery'=>false,'site_id'=>$this->siteId];
    }

    public function events(int $actorId,int $pageId): array
    {
        $this->authorize($actorId);
        $page=$this->pdo->prepare(
            'SELECT id FROM cms_pages WHERE site_id=:site AND id=:page LIMIT 1'
        );
        $page->execute(['site'=>$this->siteId,'page'=>$pageId]);
        if(!$page->fetchColumn())throw new RuntimeException('tenant_content_unavailable');
        $q=$this->pdo->prepare(
            'SELECT id,from_state,to_state,actor_id,note,created_at ' .
            'FROM cms_site_editorial_events WHERE site_id=:site ' .
            'AND page_id=:page ORDER BY id DESC LIMIT 100'
        );
        $q->execute(['site'=>$this->siteId,'page'=>$pageId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function refreshSearchIndex(int $pageId): void
    {
        // This explicit site-scoped query avoids relying on a mutable,
        // process-global SiteContext in queue/worker execution.
        $q=$this->pdo->prepare(
            'SELECT id,locale,content_type,title,body,status,trash_status ' .
            'FROM cms_pages WHERE site_id=:site AND id=:page LIMIT 1'
        );
        $q->execute(['site'=>$this->siteId,'page'=>$pageId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return;
        if($row['status']!=='published' || $row['trash_status']!=='active'){
            $remove=$this->pdo->prepare(
                'DELETE FROM cms_search_index WHERE site_id=:site AND page_id=:page'
            );
            $remove->execute(['site'=>$this->siteId,'page'=>$pageId]);
            return;
        }
        $text=trim(preg_replace('/\s+/u',' ',strip_tags((string)$row['body']))??'');
        $insert=$this->pdo->prepare(
            'INSERT INTO cms_search_index(site_id,page_id,locale,content_type,title,search_text) ' .
            'VALUES(:site,:page,:locale,:type,:title,:text) ' .
            'ON CONFLICT(page_id) DO UPDATE SET locale=excluded.locale,' .
            'content_type=excluded.content_type,title=excluded.title,' .
            'search_text=excluded.search_text,updated_at=CURRENT_TIMESTAMP ' .
            'WHERE cms_search_index.site_id=excluded.site_id'
        );
        $insert->execute([
            'site'=>$this->siteId,'page'=>$pageId,
            'locale'=>$row['locale'],'type'=>$row['content_type'],
            'title'=>$row['title'],'text'=>$text
        ]);
        if($insert->rowCount()!==1)throw new RuntimeException('tenant_editorial_index_conflict');
    }

    private function event(int $actor,int $page,string $from,string $to,string $note): void
    {
        $q=$this->pdo->prepare(
            'INSERT INTO cms_site_editorial_events(site_id,page_id,actor_id,' .
            'from_state,to_state,note) VALUES(:site,:page,:actor,:from,:to,:note)'
        );
        $q->execute([
            'site'=>$this->siteId,'page'=>$page,'actor'=>$actor,
            'from'=>$from,'to'=>$to,'note'=>mb_substr($note,0,1000)
        ]);
    }

    private function authorize(int $actor): void
    {
        if($actor<1)throw new RuntimeException('tenant_access_denied');
        $q=$this->pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m JOIN users u ON u.id=m.user_id ' .
            "WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active' LIMIT 1"
        );
        $q->execute(['site'=>$this->siteId,'actor'=>$actor]);
        if(!in_array($q->fetchColumn(),['owner','admin','editor'],true)){
            throw new RuntimeException('tenant_access_denied');
        }
    }
    private function begin(): void
    {
        if($this->pdo->inTransaction())throw new RuntimeException('tenant_editorial_ambient_transaction');
        if(!$this->pdo->beginTransaction())throw new RuntimeException('tenant_editorial_transaction_unavailable');
    }
    private function rollback(): void
    {
        if($this->pdo->inTransaction())$this->pdo->rollBack();
    }
}
