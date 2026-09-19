<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

final class ContentModel
{
    public static function contentTypes(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM cms_content_types ORDER BY content_key')->fetchAll();
    }

    public static function taxonomies(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM cms_taxonomies ORDER BY taxonomy_key')->fetchAll();
    }

    public static function terms(PDO $pdo, string $taxonomyKey): array
    {
        $q=$pdo->prepare('SELECT t.* FROM cms_terms t JOIN cms_taxonomies x ON x.id=t.taxonomy_id WHERE x.taxonomy_key=:key ORDER BY t.parent_id,t.name,t.id');
        $q->execute(['key'=>$taxonomyKey]); return $q->fetchAll();
    }

    public static function assignTerms(PDO $pdo,int $pageId,array $termIds): void
    {
        $pdo->prepare('DELETE FROM cms_term_relationships WHERE page_id=:id')->execute(['id'=>$pageId]);
        $insert=$pdo->prepare('INSERT INTO cms_term_relationships(page_id,term_id,position) VALUES(:page,:term,:position)');
        foreach(array_values(array_unique(array_map('intval',$termIds))) as $i=>$termId){if($termId>0)$insert->execute(['page'=>$pageId,'term'=>$termId,'position'=>$i]);}
    }
    public static function autosave(PDO $pdo,int $pageId,int $actorId,string $title,string $body,array $metadata=[]): array
    {
        $hash=hash('sha256',$title."\0".$body."\0".json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql=$driver==='sqlite'
            ? 'INSERT INTO cms_autosaves(page_id,actor_id,content_hash,title,body,metadata_json,saved_at) VALUES(:page,:actor,:hash,:title,:body,:meta,CURRENT_TIMESTAMP) ON CONFLICT(page_id,actor_id) DO UPDATE SET content_hash=:hash2,title=:title2,body=:body2,metadata_json=:meta2,saved_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO cms_autosaves(page_id,actor_id,content_hash,title,body,metadata_json,saved_at) VALUES(:page,:actor,:hash,:title,:body,:meta,CURRENT_TIMESTAMP) ON CONFLICT(page_id,actor_id) DO UPDATE SET content_hash=EXCLUDED.content_hash,title=EXCLUDED.title,body=EXCLUDED.body,metadata_json=EXCLUDED.metadata_json,saved_at=CURRENT_TIMESTAMP';
        $args=['page'=>$pageId?:null,'actor'=>$actorId,'hash'=>$hash,'title'=>$title,'body'=>$body,'meta'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        if($driver==='sqlite')$args+=['hash2'=>$hash,'title2'=>$title,'body2'=>$body,'meta2'=>$args['meta']];
        $pdo->prepare($sql)->execute($args); return ['hash'=>$hash,'saved'=>true];
    }

    public static function trash(PDO $pdo,int $pageId,int $actorId): void
    {
        $q=$pdo->prepare("SELECT title,body,meta_title,meta_description FROM cms_pages WHERE id=:id AND trash_status='active'");$q->execute(['id'=>$pageId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Content not found or already trashed.');
        self::revision($pdo,$pageId,$actorId,'pre-trash',$row);
        $pdo->prepare("UPDATE cms_pages SET trash_status='trash',trashed_at=CURRENT_TIMESTAMP,trashed_by=:actor,is_home=0,updated_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['actor'=>$actorId,'id'=>$pageId]);
    }

    public static function restore(PDO $pdo,int $pageId): void
    {
        $pdo->prepare("UPDATE cms_pages SET trash_status='active',trashed_at=NULL,trashed_by=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND trash_status='trash'")->execute(['id'=>$pageId]);
    }
    public static function revision(PDO $pdo,int $pageId,int $actorId,string $kind,array $row): void
    {
        $hash=hash('sha256',(string)$row['title']."\0".(string)$row['body']."\0".(string)($row['meta_title']??'')."\0".(string)($row['meta_description']??''));
        $pdo->prepare('INSERT INTO cms_page_revisions(page_id,title,body,meta_title,meta_description,created_by,revision_kind,content_hash,metadata_json) VALUES(:page,:title,:body,:meta_title,:meta_description,:actor,:kind,:hash,:metadata)')->execute(['page'=>$pageId,'title'=>$row['title'],'body'=>$row['body'],'meta_title'=>$row['meta_title']??'','meta_description'=>$row['meta_description']??'','actor'=>$actorId,'kind'=>$kind,'hash'=>$hash,'metadata'=>'{}']);
    }

    public static function canEdit(array $user,array $page): bool
    {
        if(($user['role']??'')==='owner')return true;
        if(!Access::allowed($user,'content'))return false;
        if(($user['role']??'')==='admin')return true;
        return (int)($page['author_id']??0)===(int)($user['id']??0);
    }
}
