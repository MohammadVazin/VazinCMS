<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/** Unsaved-page recovery in EXISTING cms_autosaves rows with page_id=NULL.
 * A draft never inserts cms_pages or reserves a public slug. Actor/site and
 * random draft key are checked on every read/write. No migration or DDL.
 */
final class EditorNewDraft
{
    private bool $scoped;
    public function __construct(private PDO $pdo,private int $siteId)
    {
        $this->scoped=SiteScopeMode::active($pdo);
        if($siteId<1)throw new RuntimeException('new_draft_access_denied');
    }
    public static function validKey(string $key):bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/D',$key)===1;
    }
    private function authorize(int $actorId,bool $lock=false):void
    {
        if($actorId<1)throw new RuntimeException('new_draft_access_denied');
        $q=$this->pdo->prepare("SELECT id FROM cms_sites WHERE id=:id AND status IN('active','staging')");
        $q->execute(['id'=>$this->siteId]);$site=$q->fetchColumn();$q->closeCursor();
        if(!$site)throw new RuntimeException('new_draft_access_denied');
        $sql="SELECT id,role FROM users WHERE id=:actor AND status='active'";
        if($lock&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql')$sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);$q->execute(['actor'=>$actorId]);$user=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$user)throw new RuntimeException('new_draft_access_denied');
        $role=$user['role'];
        if($this->scoped){
            $q=$this->pdo->prepare('SELECT site_role FROM cms_site_memberships WHERE site_id=:site AND user_id=:actor');
            $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);$role=$q->fetchColumn();$q->closeCursor();
        }
        $roles=$this->scoped?['owner','admin','editor','author']:['owner','admin'];
        if(!is_string($role)||!in_array($role,$roles,true))throw new RuntimeException('new_draft_access_denied');
    }
    /** Only these fields are recovered; never status/home/permissions/SEO. */
    public static function document(array $raw):array
    {
        foreach(['title','body','block_document','slug','locale','content_type'] as $key){
            if(isset($raw[$key])&&!is_string($raw[$key]))throw new InvalidArgumentException('new_draft_document_invalid');
        }
        $copy=EditorAutosave::document((string)($raw['title']??''),(string)($raw['body']??''),(string)($raw['block_document']??''));
        $slug=(string)($raw['slug']??'');$locale=(string)($raw['locale']??'fa');$type=(string)($raw['content_type']??'page');
        // Partial/unfinished slugs are recoverable text, not public URLs.
        // The manual-save boundary still enforces its strict slug syntax.
        if(mb_strlen($slug)>189 || str_contains($slug,"\0") ||
           !in_array($locale,['fa','ar','en','ru','tr','hy','kk','tg','zh'],true)||!in_array($type,['page','post'],true)){
            throw new InvalidArgumentException('new_draft_document_invalid');
        }
        return $copy+['slug'=>$slug,'locale'=>$locale,'content_type'=>$type];
    }
    private function prefix(?string $key=null):string
    {
        $meta=['new_page_schema'=>1,'site_id'=>$this->siteId];
        if($key!==null){if(!self::validKey($key))throw new InvalidArgumentException('new_draft_key_invalid');$meta['draft_key']=$key;}
        return substr(json_encode($meta,JSON_THROW_ON_ERROR),0,-1).',%';
    }
    private function row(int $actorId,string $key):?array
    {
        $sql='SELECT id,title,body,metadata_json,content_hash,saved_at FROM cms_autosaves WHERE page_id IS NULL AND actor_id=:actor AND metadata_json LIKE :prefix';
        $args=['actor'=>$actorId,'prefix'=>$this->prefix($key)];
        if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
        $q=$this->pdo->prepare($sql.' ORDER BY id LIMIT 2');$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($rows)>1)throw new RuntimeException('new_draft_integrity_failed');
        return $rows[0]??null;
    }
    private static function metadata(array $row):array
    {
        try{$m=json_decode((string)$row['metadata_json'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('new_draft_integrity_failed');}
        $expected=hash('sha256',$row['title']."\0".$row['body']."\0".$row['metadata_json']);
        if(!is_array($m)||!hash_equals($expected,(string)$row['content_hash']))throw new RuntimeException('new_draft_integrity_failed');
        return $m;
    }
    private static function token(string $token):void
    {
        if($token!==''&&!preg_match('/\A[a-f0-9]{64}\z/D',$token))throw new InvalidArgumentException('new_draft_token_invalid');
    }
    /** Also called inside EditorPageSave's transaction. */
    public function assertPending(int $actorId,string $key,string $token):?array
    {
        self::token($token);$this->authorize($actorId,true);
        $row=$this->row($actorId,$key);
        if($row){
            $m=self::metadata($row);
            if(($m['state']??'')==='consumed')throw new RuntimeException('new_draft_already_saved');
            if(($m['state']??'')!=='pending')throw new RuntimeException('new_draft_integrity_failed');
        }
        if(!hash_equals((string)($row['content_hash']??''),$token))throw new RuntimeException('new_draft_changed');
        return $row;
    }
    public function load(int $actorId,string $key):array
    {
        $this->authorize($actorId);$row=$this->row($actorId,$key);
        $out=['ok'=>true,'draft_key'=>$key,'base_revision'=>'','autosave_token'=>$row['content_hash']??'',
            'recovery'=>null,'recovery_state'=>'none'];
        if(!$row)return $out;
        $m=self::metadata($row);
        if(($m['state']??'')==='consumed')throw new RuntimeException('new_draft_already_saved');
        if(($m['state']??'')!=='pending'||!is_int($m['saved_epoch']??null)||
           $m['saved_epoch']<time()-604800||$m['saved_epoch']>time()+60){$out['recovery_state']='stale';return $out;}
        $copy=self::document(['title'=>$row['title'],'body'=>$row['body']]+($m['document']??[]));
        $out['recovery']=$copy;$out['recovery_state']='available';$out['saved_epoch']=$m['saved_epoch'];
        return $out;
    }
    private function store(int $actorId,string $key,?array $row,array $doc,string $state,?int $pageId=null):string
    {
        $meta=['new_page_schema'=>1,'site_id'=>$this->siteId,'draft_key'=>$key,'state'=>$state,
            'saved_epoch'=>time(),'revision_nonce'=>bin2hex(random_bytes(16)),
            'document'=>array_diff_key($doc,['title'=>true,'body'=>true])];
        if($pageId!==null)$meta['saved_page_id']=$pageId;
        $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $hash=hash('sha256',$doc['title']."\0".$doc['body']."\0".$json);
        $args=['actor'=>$actorId,'title'=>$doc['title'],'body'=>$doc['body'],'meta'=>$json,'hash'=>$hash];
        if($row){
            $sql='UPDATE cms_autosaves SET title=:title,body=:body,metadata_json=:meta,content_hash=:hash,saved_at=CURRENT_TIMESTAMP WHERE id=:id AND page_id IS NULL AND actor_id=:actor AND content_hash=:prior';
            $args['id']=$row['id'];$args['prior']=$row['content_hash'];
            if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
        }else{
            $cols='page_id,actor_id,title,body,metadata_json,content_hash,saved_at';
            $values='NULL,:actor,:title,:body,:meta,:hash,CURRENT_TIMESTAMP';
            if($this->scoped){$cols='site_id,'.$cols;$values=':site,'.$values;$args['site']=$this->siteId;}
            $sql='INSERT INTO cms_autosaves('.$cols.') VALUES('.$values.')';
        }
        $q=$this->pdo->prepare($sql);$q->execute($args);$n=$q->rowCount();$q->closeCursor();
        if($n!==1)throw new RuntimeException('new_draft_changed');
        return $hash;
    }
    public function save(int $actorId,string $key,string $token,array $raw):array
    {
        $doc=self::document($raw);
        if($this->pdo->inTransaction())throw new RuntimeException('new_draft_nested_transaction');
        $sqlite=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if($sqlite)$this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction();
        try{
            $row=$this->assertPending($actorId,$key,$token);
            if(!$row && count($this->recent($actorId,25))>=20)throw new RuntimeException('new_draft_limit_reached');
            $hash=$this->store($actorId,$key,$row,$doc,'pending');
            if($sqlite)$this->pdo->exec('COMMIT');else $this->pdo->commit();
            return ['ok'=>true,'saved'=>true,'draft_key'=>$key,'base_revision'=>'','autosave_token'=>$hash,'saved_epoch'=>time()];
        }catch(Throwable $e){
            try{if($sqlite)$this->pdo->exec('ROLLBACK');elseif($this->pdo->inTransaction())$this->pdo->rollBack();}catch(Throwable){}
            throw $e;
        }
    }
    /** Caller already owns the manual-save transaction and checked token. */
    public function consume(int $actorId,string $key,?array $row,array $doc,int $savedPageId):void
    {
        if($savedPageId<1)throw new RuntimeException('new_draft_invalid_saved_page');
        $this->store($actorId,$key,$row,self::document($doc),'consumed',$savedPageId);
    }
    public function recent(int $actorId,int $limit=10):array
    {
        $this->authorize($actorId);
        $sql='SELECT title,body,metadata_json,content_hash FROM cms_autosaves WHERE page_id IS NULL AND actor_id=:actor AND metadata_json LIKE :prefix AND saved_at>=:cutoff';
        // Filter pending records before LIMIT; a long sequence of consumed
        // drafts must not hide older pending drafts from quota/recovery.
        $pendingPrefix=substr($this->prefix(),0,-1).'"draft_key":"'.str_repeat('_',32).'","state":"pending",%';
        $args=['actor'=>$actorId,'prefix'=>$pendingPrefix,'cutoff'=>gmdate('Y-m-d H:i:s',time()-604800)];
        if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
        $q=$this->pdo->prepare($sql.' ORDER BY id DESC LIMIT 200');$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $out=[];
        foreach($rows as $row){
            try{$m=self::metadata($row);}catch(Throwable){continue;}
            if(($m['state']??'')!=='pending'||!self::validKey((string)($m['draft_key']??'')))continue;
            $out[]=['key'=>$m['draft_key'],'title'=>$row['title'],'content_type'=>$m['document']['content_type']??'page'];
            if(count($out)>=max(1,min(25,$limit)))break;
        }
        return $out;
    }
}
