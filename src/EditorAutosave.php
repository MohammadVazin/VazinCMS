<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Actor-scoped recovery copies in the EXISTING cms_autosaves table.
 * Never writes cms_pages, changes publication, applies migrations or grants
 * a permission. Recovery is offered to the editor, not automatically applied.
 */
final class EditorAutosave
{
    private bool $scoped;
    public function __construct(private PDO $pdo,private int $siteId)
    {
        $this->scoped=SiteScopeMode::active($pdo);
        if($siteId<1)throw new RuntimeException('autosave_access_denied');
        $q=$pdo->prepare("SELECT id FROM cms_sites WHERE id=:id AND status IN('active','staging')");
        $q->execute(['id'=>$siteId]);$found=$q->fetchColumn();$q->closeCursor();
        if(!$found)throw new RuntimeException('autosave_access_denied');
    }

    /** Stable page revision, including publication/metadata changes. */
    public static function baseRevision(array $page): string
    {
        $state=[];
        foreach(['id','site_id','title','body','block_document','block_schema_version',
            'slug','locale','status','trash_status','is_home','author_id','content_type',
            'meta_title','meta_description','canonical_url','featured_image',
            'published_at','robots_index','robots_follow','og_title','og_description',
            'schema_type','updated_at'] as $key){
            $state[$key]=isset($page[$key])?(string)$page[$key]:'';
        }
        return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    /** Only title, body and full block document are eligible for recovery. */
    public static function document(string $title,string $body,string $json): array
    {
        if(mb_strlen($title)>190 || strlen($body)>1048576 || strlen($json)>1048576){
            throw new InvalidArgumentException('autosave_document_too_large');
        }
        try{
            $doc=BlockEditor::decode($json);
            $count=0;
            $visit=static function(array $blocks,int $depth)use(&$visit,&$count):void{
                if($depth>12 || !array_is_list($blocks))throw new InvalidArgumentException('autosave_document_invalid');
                foreach($blocks as $block){
                    if(++$count>500)throw new InvalidArgumentException('autosave_document_too_large');
                    if(($block['type']??'')==='columns'){
                        $columns=$block['data']['columns']??[];
                        if(!is_array($columns)||!array_is_list($columns))throw new InvalidArgumentException('autosave_document_invalid');
                        foreach($columns as $column){
                            if(!is_array($column)||!is_array($column['blocks']??null))throw new InvalidArgumentException('autosave_document_invalid');
                            $visit($column['blocks'],$depth+1);
                        }
                    }
                }
            };
            $visit($doc['blocks'],0);
            $canonical=BlockEditor::encode($doc);
        }catch(Throwable $error){
            if($error instanceof InvalidArgumentException)throw $error;
            throw new InvalidArgumentException('autosave_document_invalid');
        }
        return ['title'=>$title,'body'=>$body,'block_document'=>$canonical];
    }

    private function page(int $actorId,int $pageId): array
    {
        if($actorId<1||$pageId<1)throw new RuntimeException('autosave_access_denied');
        $q=$this->pdo->prepare('SELECT id,role,status FROM users WHERE id=:id');
        $q->execute(['id'=>$actorId]);$user=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$user||$user['status']!=='active')throw new RuntimeException('autosave_access_denied');
        if($this->scoped){
            return (new SiteScopedContentWriter($this->pdo,$this->siteId))->page($actorId,$pageId);
        }
        // Same roles as the legacy page editor; never infer per-site access
        // from a global role on a scoped installation.
        if(!in_array($user['role'],['owner','admin'],true))throw new RuntimeException('autosave_access_denied');
        $q=$this->pdo->prepare("SELECT * FROM cms_pages WHERE id=:id AND trash_status='active'");
        $q->execute(['id'=>$pageId]);$page=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$page)throw new RuntimeException('autosave_access_denied');
        return $page;
    }

    private function stored(int $actorId,int $pageId): ?array
    {
        $sql='SELECT title,body,metadata_json,content_hash,saved_at FROM cms_autosaves WHERE page_id=:page AND actor_id=:actor';
        $args=['page'=>$pageId,'actor'=>$actorId];
        if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
        $q=$this->pdo->prepare($sql.' LIMIT 1');$q->execute($args);
        $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        return is_array($row)?$row:null;
    }

    private static function checkBase(array $page,string $base): void
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$base)||!hash_equals(self::baseRevision($page),$base)){
            throw new RuntimeException('autosave_page_changed');
        }
    }

    public function load(int $actorId,int $pageId,string $base): array
    {
        $page=$this->page($actorId,$pageId);self::checkBase($page,$base);
        $row=$this->stored($actorId,$pageId);
        $result=['ok'=>true,'base_revision'=>$base,'autosave_token'=>$row['content_hash']??'',
            'recovery'=>null,'recovery_state'=>'none'];
        if(!$row)return $result;
        try{
            $meta=json_decode((string)$row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($meta)||($meta['editor_schema']??null)!==1||
                ($meta['base_revision']??'')!==$base||
                !is_int($meta['saved_epoch']??null)||$meta['saved_epoch']<time()-604800||
                $meta['saved_epoch']>time()+60){
                $result['recovery_state']='stale';return $result;
            }
            $copy=self::document((string)$row['title'],(string)$row['body'],(string)($meta['block_document']??''));
            $current=self::document((string)$page['title'],(string)$page['body'],
                trim((string)($page['block_document']??''))!==''?(string)$page['block_document']:
                BlockEditor::encode(BlockEditor::legacy((string)$page['body'])));
            if($copy===$current)return $result;
            $result['recovery']=$copy;
            $result['recovery_state']='available';$result['saved_epoch']=$meta['saved_epoch'];
        }catch(Throwable){$result['recovery_state']='stale';}
        return $result;
    }

    public function save(int $actorId,int $pageId,string $base,string $token,array $document): array
    {
        if($token!==''&&!preg_match('/\A[a-f0-9]{64}\z/D',$token))throw new InvalidArgumentException('autosave_token_invalid');
        $copy=self::document((string)($document['title']??''),(string)($document['body']??''),(string)($document['block_document']??''));
        if($this->pdo->inTransaction())throw new RuntimeException('autosave_nested_transaction_denied');
        $sqlite=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if($sqlite)$this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction();
        try{
            // Parent-row lock serializes same-page writes on PostgreSQL;
            // BEGIN IMMEDIATE provides that guarantee on SQLite.
            if(!$sqlite){
                $sql='SELECT id FROM cms_pages WHERE id=:id';$args=['id'=>$pageId];
                if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
                $q=$this->pdo->prepare($sql.' FOR UPDATE');$q->execute($args);$q->fetchColumn();$q->closeCursor();
            }
            $page=$this->page($actorId,$pageId);self::checkBase($page,$base);
            $row=$this->stored($actorId,$pageId);
            if(!hash_equals((string)($row['content_hash']??''),$token))throw new RuntimeException('autosave_copy_changed');
            $now=time();
            $meta=['editor_schema'=>1,'base_revision'=>$base,'block_document'=>$copy['block_document'],
                'saved_epoch'=>$now,'revision_nonce'=>bin2hex(random_bytes(16))];
            // Reuse existing storage APIs and tenant authorization; no DDL.
            if($this->scoped){
                $stored=(new SiteScopedContentWriter($this->pdo,$this->siteId))->autosave($actorId,$pageId,$copy['title'],$copy['body'],$meta);
            }else{
                $stored=ContentModel::autosave($this->pdo,$pageId,$actorId,$copy['title'],$copy['body'],$meta);
            }
            if($sqlite)$this->pdo->exec('COMMIT');else $this->pdo->commit();
            return ['ok'=>true,'saved'=>true,'autosave_token'=>$stored['hash'],'base_revision'=>$base,'saved_epoch'=>$now];
        }catch(Throwable $error){
            try{if($sqlite)$this->pdo->exec('ROLLBACK');elseif($this->pdo->inTransaction())$this->pdo->rollBack();}catch(Throwable){}
            throw $error;
        }
    }
}
