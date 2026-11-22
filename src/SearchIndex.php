<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;

final class SearchIndex
{
    public static function refreshPage(PDO $pdo, int $pageId): void
    {
        $scoped = SiteScopeMode::active($pdo);
        $siteId = $scoped ? SiteContext::id() : null;
        if ($scoped) {
            $q = $pdo->prepare(
                'SELECT id,locale,content_type,title,body,trash_status,status,site_id ' .
                'FROM cms_pages WHERE id=:id AND site_id=:site'
            );
            $q->execute(['id'=>$pageId, 'site'=>$siteId]);
        } else {
            $q = $pdo->prepare(
                'SELECT id,locale,content_type,title,body,trash_status,status ' .
                'FROM cms_pages WHERE id=:id'
            );
            $q->execute(['id'=>$pageId]);
        }
        $page = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($page) || ($page['trash_status']??'active')!=='active' ||
            ($page['status']??'draft')!=='published') {
            $sql=$scoped ?
                'DELETE FROM cms_search_index WHERE page_id=:id AND site_id=:site' :
                'DELETE FROM cms_search_index WHERE page_id=:id';
            $delete=$pdo->prepare($sql);
            $delete->execute($scoped?['id'=>$pageId,'site'=>$siteId]:['id'=>$pageId]);
            return;
        }
        $text=trim(preg_replace('/\s+/u',' ',strip_tags((string)$page['body']))??'');
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($scoped) {
            $sql = 'INSERT INTO cms_search_index(site_id,page_id,locale,content_type,title,search_text) ' .
                'VALUES(:site,:id,:locale,:type,:title,:text) ' .
                'ON CONFLICT(page_id) DO UPDATE SET ' .
                'locale=excluded.locale,content_type=excluded.content_type,' .
                'title=excluded.title,search_text=excluded.search_text,' .
                'updated_at=CURRENT_TIMESTAMP WHERE cms_search_index.site_id=excluded.site_id';
            $insert=$pdo->prepare($sql);
            $insert->execute([
                'site'=>$siteId,'id'=>$pageId,'locale'=>$page['locale'],
                'type'=>$page['content_type'],'title'=>$page['title'],'text'=>$text
            ]);
            if ($insert->rowCount()===0) {
                throw new RuntimeException('tenant_search_index_owner_mismatch');
            }
            return;
        }
        if ($driver==='sqlite') {
            $pdo->prepare(
                'INSERT INTO cms_search_index(page_id,locale,content_type,title,search_text) ' .
                'VALUES(:id,:locale,:type,:title,:text) ' .
                'ON CONFLICT(page_id) DO UPDATE SET locale=:locale2,content_type=:type2,' .
                'title=:title2,search_text=:text2,updated_at=CURRENT_TIMESTAMP'
            )->execute([
                'id'=>$pageId,'locale'=>$page['locale'],'type'=>$page['content_type'],
                'title'=>$page['title'],'text'=>$text,
                'locale2'=>$page['locale'],'type2'=>$page['content_type'],
                'title2'=>$page['title'],'text2'=>$text
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO cms_search_index(page_id,locale,content_type,title,search_text) ' .
                'VALUES(:id,:locale,:type,:title,:text) ' .
                'ON CONFLICT(page_id) DO UPDATE SET locale=EXCLUDED.locale,' .
                'content_type=EXCLUDED.content_type,title=EXCLUDED.title,' .
                'search_text=EXCLUDED.search_text,updated_at=CURRENT_TIMESTAMP'
            )->execute([
                'id'=>$pageId,'locale'=>$page['locale'],'type'=>$page['content_type'],
                'title'=>$page['title'],'text'=>$text
            ]);
        }
    }

    public static function search(PDO $pdo, string $query, string $locale='', string $type='', int $limit=50): array
    {
        $query=trim($query);
        if ($query==='') return [];
        if (SiteScopeMode::active($pdo)) {
            $items=(new SiteScopedContentRepository($pdo,SiteContext::id()))->search($query,$locale);
            if ($type!=='') {
                $items=array_values(array_filter($items,
                    static fn(array $item):bool=>($item['content_type']??'')===$type));
            }
            return array_slice($items,0,max(1,min(200,$limit)));
        }
        $where=['(lower(title) LIKE :q OR lower(search_text) LIKE :q)'];
        $args=['q'=>'%'.mb_strtolower($query).'%'];
        if ($locale!=='') {$where[]='locale=:locale';$args['locale']=$locale;}
        if ($type!=='') {$where[]='content_type=:type';$args['type']=$type;}
        $sql='SELECT page_id,locale,content_type,title FROM cms_search_index WHERE ' .
            implode(' AND ',$where).' ORDER BY title LIMIT '.max(1,min(200,$limit));
        $s=$pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
