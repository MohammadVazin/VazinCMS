<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;

final class SearchIndex
{
    public static function refreshPage(PDO $pdo,int $pageId): void
    {
        $q=$pdo->prepare("SELECT id,locale,content_type,title,body,trash_status,status FROM cms_pages WHERE id=:id");$q->execute(['id'=>$pageId]);$p=$q->fetch();
        if(!$p||($p['trash_status']??'active')!=='active'||($p['status']??'draft')!=='published'){$pdo->prepare('DELETE FROM cms_search_index WHERE page_id=:id')->execute(['id'=>$pageId]);return;}
        $text=trim(preg_replace('/\s+/u',' ',strip_tags((string)$p['body']))??'');$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$pdo->prepare('INSERT INTO cms_search_index(page_id,locale,content_type,title,search_text) VALUES(:id,:locale,:type,:title,:text) ON CONFLICT(page_id) DO UPDATE SET locale=:locale2,content_type=:type2,title=:title2,search_text=:text2,updated_at=CURRENT_TIMESTAMP')->execute(['id'=>$pageId,'locale'=>$p['locale'],'type'=>$p['content_type'],'title'=>$p['title'],'text'=>$text,'locale2'=>$p['locale'],'type2'=>$p['content_type'],'title2'=>$p['title'],'text2'=>$text]);
        else $pdo->prepare('INSERT INTO cms_search_index(page_id,locale,content_type,title,search_text) VALUES(:id,:locale,:type,:title,:text) ON CONFLICT(page_id) DO UPDATE SET locale=EXCLUDED.locale,content_type=EXCLUDED.content_type,title=EXCLUDED.title,search_text=EXCLUDED.search_text,updated_at=CURRENT_TIMESTAMP')->execute(['id'=>$pageId,'locale'=>$p['locale'],'type'=>$p['content_type'],'title'=>$p['title'],'text'=>$text]);
    }

    public static function search(PDO $pdo,string $query,string $locale='',string $type='',int $limit=50): array
    {
        $query=trim($query);if($query==='')return[];$where=['(lower(title) LIKE :q OR lower(search_text) LIKE :q)'];$args=['q'=>'%'.mb_strtolower($query).'%'];
        if($locale!==''){$where[]='locale=:locale';$args['locale']=$locale;}if($type!==''){$where[]='content_type=:type';$args['type']=$type;}
        $sql='SELECT page_id,locale,content_type,title FROM cms_search_index WHERE '.implode(' AND ',$where).' ORDER BY title LIMIT '.max(1,min(200,$limit));$s=$pdo->prepare($sql);$s->execute($args);return$s->fetchAll();
    }
}
