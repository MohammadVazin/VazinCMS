<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use SimpleXMLElement;

final class WordPressImporter
{
    public static function importFile(PDO $pdo,string $path,array $options=[]): array
    {
        if(!is_file($path))throw new RuntimeException('WXR file not found.');$fingerprint=hash_file('sha256',$path)?:throw new RuntimeException('WXR fingerprint failed.');
        $job=self::job($pdo,$fingerprint,$options);$xml=simplexml_load_file($path,SimpleXMLElement::class,LIBXML_NONET|LIBXML_NOCDATA);if(!$xml)throw new RuntimeException('Invalid WXR XML.');
        $ns=$xml->getNamespaces(true);$channel=$xml->channel;$stats=['job_id'=>$job,'scanned'=>0,'created'=>0,'updated'=>0,'skipped'=>0,'terms'=>0,'authors'=>0,'attachments'=>0,'redirects'=>0,'errors'=>0];
        foreach($channel->children($ns['wp']??'')->author??[] as$author){$stats['authors']++;}
        foreach($channel->item as$item){$stats['scanned']++;try{self::importItem($pdo,$job,$item,$ns,$options,$stats);}catch(\Throwable $e){$stats['errors']++;}}
        $pdo->prepare("UPDATE cms_import_jobs SET status='completed',stats_json=:stats,finished_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['stats'=>json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$job]);$pdo->prepare('INSERT INTO cms_import_reports(job_id,report_json) VALUES(:job,:report)')->execute(['job'=>$job,'report'=>json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);return$stats;
    }
    private static function job(PDO $pdo,string $fingerprint,array $options): int
    {
        $q=$pdo->prepare('SELECT id,status FROM cms_import_jobs WHERE source_fingerprint=:f');$q->execute(['f'=>$fingerprint]);if($r=$q->fetch())return(int)$r['id'];$pdo->prepare("INSERT INTO cms_import_jobs(source_type,source_fingerprint,status,options_json) VALUES('wordpress_wxr',:f,'processing',:o)")->execute(['f'=>$fingerprint,'o'=>json_encode($options,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);return(int)$pdo->lastInsertId();
    }

    private static function importItem(PDO $pdo,int $job,SimpleXMLElement $item,array $ns,array $options,array &$stats): void
    {
        $wp=$item->children($ns['wp']??'');$content=$item->children($ns['content']??'');$postId=(string)($wp->post_id??'');$postType=(string)($wp->post_type??'post');$status=(string)($wp->status??'draft');$slug=(string)($wp->post_name??'');$title=trim((string)$item->title);$body=(string)($content->encoded??'');$link=(string)$item->link;
        if($postId===''||in_array($postType,['nav_menu_item','revision'],true)){$stats['skipped']++;return;}
        $map=$pdo->prepare("SELECT target_id FROM cms_import_mappings WHERE job_id=:job AND source_kind='post' AND source_id=:source");$map->execute(['job'=>$job,'source'=>$postId]);if($map->fetchColumn()){$stats['skipped']++;return;}
        if($postType==='attachment'){self::recordAttachment($pdo,$job,$item,$wp,$ns,$stats);return;}
        $ctype=in_array($postType,['post','page'],true)?$postType:$postType;self::ensureContentType($pdo,$ctype,$postType);
        $meta=self::postMeta($item,$ns);$seoTitle=(string)($meta['_yoast_wpseo_title']??$title);$seoDesc=(string)($meta['_yoast_wpseo_metadesc']??'');$published=in_array($status,['publish','future'],true)?'published':'draft';$locale=(string)($options['locale']??'en');
        $doc=BlockEditor::legacy(strip_tags($body));$pdo->prepare("INSERT INTO cms_pages(slug,locale,title,body,status,is_home,author_id,meta_title,meta_description,canonical_url,featured_image,content_type,published_at,robots_index,robots_follow,og_title,og_description,schema_type,block_schema_version,block_document,trash_status,editorial_state,source_provider,source_ref) VALUES(:slug,:locale,:title,:body,:status,0,NULL,:meta_title,:meta_description,:canonical,'',:type,:published,1,1,:og_title,:og_description,:schema,1,:blocks,'active',:editorial,'wordpress',:ref)")->execute(['slug'=>$slug!==''?$slug:'wp-'.$postId,'locale'=>$locale,'title'=>$title,'body'=>$body,'status'=>$published,'meta_title'=>$seoTitle,'meta_description'=>$seoDesc,'canonical'=>$link,'type'=>$ctype,'published'=>$published==='published'?((string)($wp->post_date_gmt??'')) : null,'og_title'=>$seoTitle,'og_description'=>$seoDesc,'schema'=>$ctype==='post'?'Article':'WebPage','blocks'=>BlockEditor::encode($doc),'editorial'=>$published,'ref'=>$postId]);$page=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cms_import_mappings(job_id,source_kind,source_id,target_kind,target_id,metadata_json) VALUES(:job,'post',:source,'page',:target,:meta)")->execute(['job'=>$job,'source'=>$postId,'target'=>$page,'meta'=>json_encode(['post_type'=>$postType,'link'=>$link],JSON_UNESCAPED_SLASHES)]);self::importTerms($pdo,$page,$item,$ns,$stats);if($link!==''){ $path=parse_url($link,PHP_URL_PATH); if(is_string($path)&&$path!==''){$target='/'.$locale.'/page/'.rawurlencode($slug!==''?$slug:'wp-'.$postId);try{self::saveRedirect($pdo,$path,$target);$stats['redirects']++;}catch(\Throwable){}}}$stats['created']++;
    }
    private static function postMeta(SimpleXMLElement $item,array $ns): array
    {
        $out=[];$wp=$item->children($ns['wp']??'');foreach($wp->postmeta??[] as$m){$out[(string)$m->meta_key]=(string)$m->meta_value;}return$out;
    }
    private static function ensureContentType(PDO $pdo,string $key,string $label): void
    {
        $q=$pdo->prepare('SELECT 1 FROM cms_content_types WHERE content_key=:key');$q->execute(['key'=>$key]);if($q->fetchColumn())return;$pdo->prepare("INSERT INTO cms_content_types(content_key,label,singular_label,public,hierarchical,supports_json) VALUES(:key,:label,:label,1,0,'[\"title\",\"editor\",\"revisions\",\"author\",\"custom-fields\"]')")->execute(['key'=>$key,'label'=>$label]);
    }
    private static function importTerms(PDO $pdo,int $pageId,SimpleXMLElement $item,array $ns,array &$stats): void
    {
        foreach($item->category??[] as$c){$domain=(string)($c['domain']??'category');$slug=(string)($c['nicename']??'');$name=trim((string)$c);if($slug===''||$name==='')continue;$taxonomy=$domain==='post_tag'?'tag':($domain==='category'?'category':preg_replace('/[^a-z0-9_-]/i','-',strtolower($domain)));$t=$pdo->prepare('SELECT id FROM cms_taxonomies WHERE taxonomy_key=:key');$t->execute(['key'=>$taxonomy]);$taxId=$t->fetchColumn();if(!$taxId){$pdo->prepare('INSERT INTO cms_taxonomies(taxonomy_key,label,singular_label,hierarchical,public) VALUES(:key,:label,:label,:hier,1)')->execute(['key'=>$taxonomy,'label'=>$taxonomy,'hier'=>$taxonomy==='category'?1:0]);$taxId=$pdo->lastInsertId();}$term=$pdo->prepare('SELECT id FROM cms_terms WHERE taxonomy_id=:tax AND slug=:slug');$term->execute(['tax'=>$taxId,'slug'=>$slug]);$termId=$term->fetchColumn();if(!$termId){$pdo->prepare("INSERT INTO cms_terms(taxonomy_id,slug,name,description) VALUES(:tax,:slug,:name,'')")->execute(['tax'=>$taxId,'slug'=>$slug,'name'=>$name]);$termId=$pdo->lastInsertId();$stats['terms']++;}$pdo->prepare('INSERT OR IGNORE INTO cms_term_relationships(page_id,term_id,position) VALUES(:page,:term,0)')->execute(['page'=>$pageId,'term'=>$termId]);}
    }
    private static function saveRedirect(PDO $pdo,string $source,string $target): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$pdo->prepare("INSERT INTO cms_redirects(source_path,target_url,status_code,created_by) VALUES(:source,:target,301,NULL) ON CONFLICT(source_path) DO UPDATE SET target_url=:target2,status_code=301,updated_at=CURRENT_TIMESTAMP")->execute(['source'=>$source,'target'=>$target,'target2'=>$target]);
        else $pdo->prepare("INSERT INTO cms_redirects(source_path,target_url,status_code,created_by) VALUES(:source,:target,301,NULL) ON CONFLICT(source_path) DO UPDATE SET target_url=EXCLUDED.target_url,status_code=301,updated_at=CURRENT_TIMESTAMP")->execute(['source'=>$source,'target'=>$target]);
    }

    private static function recordAttachment(PDO $pdo,int $job,SimpleXMLElement $item,SimpleXMLElement $wp,array $ns,array &$stats): void
    {
        $url=(string)($wp->attachment_url??'');if($url==='')return;$pdo->prepare("INSERT OR IGNORE INTO cms_import_media(job_id,source_url,status) VALUES(:job,:url,'pending')")->execute(['job'=>$job,'url'=>$url]);$stats['attachments']++;
    }
}
