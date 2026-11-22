<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/** Complete historical content snapshots in EXISTING metadata_json.
 * No schema migration. Restoration is an explicit authenticated operation;
 * it never changes slug, locale, status, ownership or unrelated metadata.
 */
final class ContentRevision
{
    private bool $scoped;
    public function __construct(private PDO $pdo,private int $siteId)
    {
        $this->scoped=SiteScopeMode::active($pdo);
        if($siteId<1)throw new RuntimeException('revision_access_denied');
        $q=$pdo->prepare("SELECT id FROM cms_sites WHERE id=:id AND status IN('active','staging')");
        $q->execute(['id'=>$siteId]);$found=$q->fetchColumn();$q->closeCursor();
        if(!$found)throw new RuntimeException('revision_access_denied');
    }

    public static function snapshot(array $row): array
    {
        $body=(string)($row['body']??'');
        $json=trim((string)($row['block_document']??''));
        if($json==='')$json=BlockEditor::encode(BlockEditor::legacy($body));
        $copy=EditorAutosave::document((string)($row['title']??''),$body,$json);
        return [
            'title'=>$copy['title'],'body'=>$copy['body'],
            'meta_title'=>(string)($row['meta_title']??''),
            'meta_description'=>(string)($row['meta_description']??''),
            'block_schema_version'=>1,'block_document'=>$copy['block_document'],
        ];
    }

    private static function digest(array $snapshot): string
    {
        return hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    /** Called only inside an already-authorized save/restore transaction. */
    public static function record(PDO $pdo,int $pageId,int $actorId,string $kind,array $page,?int $siteId=null): void
    {
        if($pageId<1||$actorId<1||($siteId!==null&&$siteId<1)||
           !in_array($kind,['manual','restore','pre-trash','autosave'],true)){
            throw new InvalidArgumentException('revision_parameters_invalid');
        }
        $s=self::snapshot($page);
        $meta=json_encode(['revision_schema'=>1,'block_schema_version'=>1,'block_document'=>$s['block_document']],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $cols='page_id,title,body,meta_title,meta_description,created_by,revision_kind,content_hash,metadata_json';
        $values=':page,:title,:body,:meta_title,:meta_description,:actor,:kind,:hash,:metadata';
        $args=['page'=>$pageId,'title'=>$s['title'],'body'=>$s['body'],'meta_title'=>$s['meta_title'],
            'meta_description'=>$s['meta_description'],'actor'=>$actorId,'kind'=>$kind,
            'hash'=>self::digest($s),'metadata'=>$meta];
        if($siteId!==null){$cols='site_id,'.$cols;$values=':site,'.$values;$args['site']=$siteId;}
        $q=$pdo->prepare('INSERT INTO cms_page_revisions('.$cols.') VALUES('.$values.')');
        $q->execute($args);$q->closeCursor();
    }

    /** Old text-only versions cannot recover blocks that were never saved.
     * Rebuild a matching plain-text document instead of leaving NEW blocks
     * alongside OLD body text. Corrupt structured versions fail closed.
     */
    public static function payload(array $revision): array
    {
        try{$meta=json_decode((string)($revision['metadata_json']??'{}'),true,32,JSON_THROW_ON_ERROR);}
        catch(Throwable){throw new RuntimeException('revision_snapshot_invalid');}
        if(!is_array($meta))throw new RuntimeException('revision_snapshot_invalid');
        $legacy=$meta===[];
        if(!$legacy && (($meta['revision_schema']??null)!==1 ||
            ($meta['block_schema_version']??null)!==1 || !is_string($meta['block_document']??null))){
            throw new RuntimeException('revision_snapshot_invalid');
        }
        try{
            $data=$revision;
            $data['block_document']=$legacy?BlockEditor::encode(BlockEditor::legacy((string)$revision['body'])):$meta['block_document'];
            $s=self::snapshot($data);
        }catch(Throwable){throw new RuntimeException('revision_snapshot_invalid');}
        $hash=(string)($revision['content_hash']??'');
        if($legacy){
            $old=hash('sha256',$s['title']."\0".$s['body']."\0".$s['meta_title']."\0".$s['meta_description']);
            if($hash!==''&&!hash_equals($old,$hash))throw new RuntimeException('revision_snapshot_invalid');
        }elseif(!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!hash_equals(self::digest($s),$hash)){
            throw new RuntimeException('revision_snapshot_invalid');
        }
        return $s+['legacy_text_only'=>$legacy];
    }

    private function role(int $actorId): string
    {
        if($actorId<1)throw new RuntimeException('revision_access_denied');
        if($this->scoped){
            $q=$this->pdo->prepare('SELECT m.site_role FROM cms_site_memberships m JOIN users u ON u.id=m.user_id '.
                "WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active'");
            $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        }else{
            $q=$this->pdo->prepare("SELECT role FROM users WHERE id=:actor AND status='active'");
            $q->execute(['actor'=>$actorId]);
        }
        $role=$q->fetchColumn();$q->closeCursor();
        $allowed=$this->scoped?['owner','admin','editor','author']:['owner','admin'];
        if(!is_string($role)||!in_array($role,$allowed,true))throw new RuntimeException('revision_access_denied');
        return $role;
    }

    private function page(int $actorId,int $pageId,bool $lock=false): array
    {
        $role=$this->role($actorId);
        $sql="SELECT * FROM cms_pages WHERE id=:id AND trash_status='active'";
        $args=['id'=>$pageId];
        if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
        if($lock&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql')$sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);$q->execute($args);$p=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$p||($role==='author'&&(int)($p['author_id']??0)!==$actorId))throw new RuntimeException('revision_unavailable');
        return $p;
    }

    public function listing(int $actorId,int $pageId=0,int $limit=100): array
    {
        $role=$this->role($actorId);$pages=[];
        if($pageId>0)$pages[$pageId]=$this->page($actorId,$pageId);
        $sql='SELECT r.*,p.title AS page_title,p.locale,p.status AS page_status FROM cms_page_revisions r '.
            'JOIN cms_pages p ON p.id=r.page_id'.($this->scoped?' AND p.site_id=r.site_id':'').
            " WHERE p.trash_status='active'";
        $args=[];
        if($this->scoped){$sql.=' AND r.site_id=:site';$args['site']=$this->siteId;}
        if($pageId>0){$sql.=' AND p.id=:page';$args['page']=$pageId;}
        if($role==='author'){$sql.=' AND p.author_id=:actor';$args['actor']=$actorId;}
        $q=$this->pdo->prepare($sql.' ORDER BY r.id DESC LIMIT '.max(1,min(100,$limit)));
        $q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($rows as &$row){
            $id=(int)$row['page_id'];$pages[$id]??=$this->page($actorId,$id);
            $row['base_revision']=EditorAutosave::baseRevision($pages[$id]);
            try{
                $payload=self::payload($row);
                $row['snapshot_state']=$payload['legacy_text_only']?'legacy':'complete';
                $doc=BlockEditor::decode($payload['block_document']);
                $row['top_level_blocks']=count($doc['blocks']);
            }catch(Throwable){$row['snapshot_state']='invalid';$row['top_level_blocks']=0;}
        }
        unset($row);return $rows;
    }

    /** Atomic explicit restore plus a complete pre-restore backup.
     * HTTP always supplies expectedBase; null is reserved for existing
     * authorized internal callers, not accepted from the web controller.
     */
    public function restore(int $actorId,int $revisionId,?string $expectedBase): int
    {
        $this->role($actorId);
        if($revisionId<1)throw new RuntimeException('revision_unavailable');
        if($expectedBase!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$expectedBase))throw new RuntimeException('revision_page_changed');
        if($this->pdo->inTransaction())throw new RuntimeException('revision_nested_transaction_denied');
        $sqlite=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if($sqlite)$this->pdo->exec('BEGIN IMMEDIATE');else $this->pdo->beginTransaction();
        try{
            $sql='SELECT * FROM cms_page_revisions WHERE id=:id';$args=['id'=>$revisionId];
            if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
            $q=$this->pdo->prepare($sql);$q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$r)throw new RuntimeException('revision_unavailable');
            $pageId=(int)$r['page_id'];$page=$this->page($actorId,$pageId,true);
            if($expectedBase!==null&&!hash_equals(EditorAutosave::baseRevision($page),$expectedBase))throw new RuntimeException('revision_page_changed');
            $s=self::payload($r);
            self::record($this->pdo,$pageId,$actorId,'restore',$page,$this->scoped?$this->siteId:null);
            $args=['title'=>$s['title'],'body'=>$s['body'],'meta_title'=>$s['meta_title'],
                'meta_description'=>$s['meta_description'],'schema'=>1,'document'=>$s['block_document'],'id'=>$pageId];
            $sql='UPDATE cms_pages SET title=:title,body=:body,meta_title=:meta_title,meta_description=:meta_description,'.
                'block_schema_version=:schema,block_document=:document,updated_at=CURRENT_TIMESTAMP WHERE id=:id';
            if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
            $q=$this->pdo->prepare($sql);$q->execute($args);
            if($q->rowCount()!==1)throw new RuntimeException('revision_unavailable');$q->closeCursor();
            if($sqlite)$this->pdo->exec('COMMIT');else $this->pdo->commit();
            return $pageId;
        }catch(Throwable $error){
            try{if($sqlite)$this->pdo->exec('ROLLBACK');elseif($this->pdo->inTransaction())$this->pdo->rollBack();}catch(Throwable){}
            throw $error;
        }
    }
}
