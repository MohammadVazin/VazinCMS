<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/** Guarded HTTP editor save. Unlike historical internal writers, this API
 * REQUIRES an observed base hash for an existing page and a per-form draft
 * key/token for first save. Conflict checks precede ALL page/history writes.
 */
final class EditorPageSave
{
    private bool $scoped;
    private const COLUMNS=['slug','locale','title','body','status','home','meta_title','meta_description',
        'canonical_url','featured_image','content_type','published_at','robots_index','robots_follow',
        'og_title','og_description','schema_type','block_schema_version','block_document'];
    public function __construct(private PDO $pdo,private int $siteId)
    {
        $this->scoped=SiteScopeMode::active($pdo);
        if($siteId<1)throw new RuntimeException('manual_save_access_denied');
    }
    private function authorize(int $actorId):string
    {
        $q=$this->pdo->prepare("SELECT id FROM cms_sites WHERE id=:site AND status IN('active','staging')");
        $q->execute(['site'=>$this->siteId]);$site=$q->fetchColumn();$q->closeCursor();
        if(!$site||$actorId<1)throw new RuntimeException('manual_save_access_denied');
        if($this->scoped){
            $q=$this->pdo->prepare("SELECT m.site_role FROM users u JOIN cms_site_memberships m ON m.user_id=u.id WHERE m.site_id=:site AND u.id=:actor AND u.status='active'");
            $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        }else{
            $q=$this->pdo->prepare("SELECT role FROM users WHERE id=:actor AND status='active'");$q->execute(['actor'=>$actorId]);
        }
        $role=$q->fetchColumn();$q->closeCursor();
        if(!is_string($role)||!in_array($role,$this->scoped?['owner','admin','editor','author']:['owner','admin'],true)){
            throw new RuntimeException('manual_save_access_denied');
        }
        return $role;
    }
    private function page(int $actorId,int $pageId):array
    {
        $role=$this->authorize($actorId);
        $sql="SELECT * FROM cms_pages WHERE id=:id AND trash_status='active'";$args=['id'=>$pageId];
        if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql')$sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);$q->execute($args);$page=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$page||($role==='author'&&(int)$page['author_id']!==$actorId))throw new RuntimeException('manual_save_content_unavailable');
        return $page;
    }
    private static function fields(array $raw):array
    {
        $copy=EditorAutosave::document((string)($raw['title']??''),(string)($raw['body']??''),(string)($raw['block_document']??''));
        $params=[];
        foreach(self::COLUMNS as $key){
            $params[$key]=$raw[$key]??match($key){
                'home'=>0,'robots_index','robots_follow','block_schema_version'=>1,
                'status'=>'draft','content_type'=>'page','schema_type'=>'WebPage','published_at'=>null,
                default=>''
            };
        }
        $params['title']=$copy['title'];$params['body']=$copy['body'];$params['block_document']=$copy['block_document'];
        if(!preg_match('/\A[a-z0-9][a-z0-9-]{0,188}\z/D',(string)$params['slug']) ||
           !in_array($params['locale'],['fa','ar','en','ru','tr','hy','kk','tg','zh'],true)||mb_strlen($params['title'])<2||
           !in_array($params['status'],['draft','published'],true)||
           !in_array($params['schema_type'],['WebPage','Article','BlogPosting','NewsArticle'],true)||
           !is_string($params['content_type'])||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$params['content_type'])){
            throw new InvalidArgumentException('manual_save_invalid_content');
        }
        foreach(['meta_title'=>255,'meta_description'=>320,'canonical_url'=>2048,'featured_image'=>2048,
            'og_title'=>255,'og_description'=>500] as $key=>$max){
            if(!is_string($params[$key])||mb_strlen($params[$key])>$max)throw new InvalidArgumentException('manual_save_invalid_metadata');
        }
        foreach(['home','robots_index','robots_follow'] as $key)$params[$key]=!empty($params[$key])?1:0;
        if($params['content_type']==='post')$params['home']=0;
        $params['block_schema_version']=1;
        return $params;
    }
    public function save(int $actorId,int $pageId,array $raw,string $expectedBase,string $draftKey='',string $draftToken=''):int
    {
        $fields=self::fields($raw);
        if($pageId<0)throw new InvalidArgumentException('manual_save_invalid_id');
        if($pageId>0&&!preg_match('/\A[a-f0-9]{64}\z/D',$expectedBase))throw new RuntimeException('manual_save_page_changed');
        if($pageId===0&&!EditorNewDraft::validKey($draftKey))throw new RuntimeException('new_draft_key_required');
        if($this->pdo->inTransaction())throw new RuntimeException('manual_save_nested_transaction');
        $sqlite=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if($sqlite)$this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction();
        try{
            $this->authorize($actorId);
            $old=null;$pending=null;$drafts=null;
            if($pageId>0){
                $old=$this->page($actorId,$pageId);
                if(!hash_equals(EditorAutosave::baseRevision($old),$expectedBase))throw new RuntimeException('manual_save_page_changed');
            }else{
                $drafts=new EditorNewDraft($this->pdo,$this->siteId);
                $pending=$drafts->assertPending($actorId,$draftKey,$draftToken);
            }
            // It is now safe to create a backup and modify this page/home.
            if($old)ContentRevision::record($this->pdo,$pageId,$actorId,'manual',$old,$this->scoped?$this->siteId:null);
            if($fields['home']){
                $sql='UPDATE cms_pages SET is_home=0 WHERE locale=:locale';$args=['locale'=>$fields['locale']];
                if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
                $q=$this->pdo->prepare($sql);$q->execute($args);$q->closeCursor();
            }
            $args=$fields;
            if($old){
                $assign=[];foreach(self::COLUMNS as $col)$assign[]=($col==='home'?'is_home=:home':$col.'=:'.$col);
                $sql='UPDATE cms_pages SET '.implode(',',$assign).',updated_at=CURRENT_TIMESTAMP WHERE id=:id';
                $args['id']=$pageId;if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
                $q=$this->pdo->prepare($sql);$q->execute($args);$n=$q->rowCount();$q->closeCursor();
                if($n!==1)throw new RuntimeException('manual_save_content_unavailable');
            }else{
                $cols=[];$values=[];
                foreach(self::COLUMNS as $col){$cols[]=($col==='home'?'is_home':$col);$values[]=':'.$col;}
                $cols[]='author_id';$values[]=':actor';$args['actor']=$actorId;
                if($this->scoped){$cols[]='site_id';$values[]=':site';$args['site']=$this->siteId;}
                $q=$this->pdo->prepare('INSERT INTO cms_pages('.implode(',',$cols).') VALUES('.implode(',',$values).')');
                $q->execute($args);$q->closeCursor();$pageId=(int)$this->pdo->lastInsertId();
                if($pageId<1)throw new RuntimeException('manual_save_insert_failed');
                $drafts->consume($actorId,$draftKey,$pending,$fields,$pageId);
            }
            if($sqlite)$this->pdo->exec('COMMIT');else $this->pdo->commit();
            return $pageId;
        }catch(Throwable $error){
            try{if($sqlite)$this->pdo->exec('ROLLBACK');elseif($this->pdo->inTransaction())$this->pdo->rollBack();}catch(Throwable){}
            throw $error;
        }
    }
    public static function isConflict(Throwable $error):bool
    {
        return in_array($error->getMessage(),['manual_save_page_changed','new_draft_changed','new_draft_already_saved','new_draft_key_required'],true);
    }
    /** Re-display only submitted fields; never silently replace attempted
     * content with the newer server version or assign it a fresh base hash. */
    public static function attemptedForm(array $post):array
    {
        $out=['id'=>max(0,(int)($post['id']??0))];
        foreach(self::COLUMNS as $key){
            if($key==='home'){$out['is_home']=isset($post['is_home'])?1:0;continue;}
            if(in_array($key,['robots_index','robots_follow'],true)){$out[$key]=isset($post[$key])?1:0;continue;}
            $value=$post[$key]??'';$out[$key]=is_scalar($value)?(string)$value:'';
        }
        return $out;
    }
}
