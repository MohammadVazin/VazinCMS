<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Tenant-scoped, draft-only WordPress WXR ingestion (beta).
 *
 * This never performs remote requests, downloads attachments, publishes posts,
 * evaluates markup, or installs WordPress plugins. Every job and mapping is
 * pinned to one verified CMS site and an explicitly permitted active user.
 * Tables are introduced by a separate, unapproved migration proposal.
 * The global SiteIsolationGate must remain active.
 */
final class SiteScopedWordPressImporter
{
    private const MAX_FILE_BYTES=5_000_000;
    private const MAX_ITEMS=500;
    private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];

    public static function importFile(
        PDO $pdo,
        string $filePath,
        int $siteId,
        int $actorId,
        array $options=[]
    ): array {
        self::authorize($pdo,$siteId,$actorId);
        self::assertSchema($pdo);
        if (!is_file($filePath) || is_link($filePath) ||
            ($size=filesize($filePath))===false || $size<1 ||
            $size>self::MAX_FILE_BYTES) {
            throw new RuntimeException('tenant_wxr_file_invalid');
        }
        $xmlBytes=file_get_contents($filePath);
        if (!is_string($xmlBytes) || strlen($xmlBytes)>self::MAX_FILE_BYTES ||
            stripos($xmlBytes,'<!DOCTYPE')!==false ||
            stripos($xmlBytes,'<!ENTITY')!==false) {
            throw new RuntimeException('tenant_wxr_xml_unsafe');
        }
        $locale=(string)($options['locale']??'en');
        if (!in_array($locale,self::LOCALES,true)) {
            throw new RuntimeException('tenant_wxr_locale_invalid');
        }

        $old=libxml_use_internal_errors(true);
        try {
            $xml=simplexml_load_string(
                $xmlBytes,SimpleXMLElement::class,LIBXML_NONET|LIBXML_NOCDATA
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        if (!$xml || $xml->getName()!=='rss' || !isset($xml->channel)) {
            throw new RuntimeException('tenant_wxr_xml_invalid');
        }
        $namespace=$xml->getNamespaces(true);
        if (empty($namespace['wp']) ||
            !str_starts_with((string)$namespace['wp'],'http://wordpress.org/export/')) {
            throw new RuntimeException('tenant_wxr_not_wordpress');
        }
        $fingerprint=hash('sha256',$xmlBytes."\0".$locale);
        $sourceHash=hash('sha256',$xmlBytes);
        $existing=$pdo->prepare(
            'SELECT id,status,stats_json FROM cms_site_import_jobs ' .
            'WHERE site_id=:site AND source_fingerprint=:fingerprint LIMIT 1'
        );

        // The only supported transaction mode is a self-owned unit of work.
        // An unexpected ambient transaction could make replay protection
        // depend on unrelated caller code; reject rather than guessing.
        if ($pdo->inTransaction())throw new RuntimeException('tenant_wxr_transaction_busy');
        if (!$pdo->beginTransaction())throw new RuntimeException('tenant_wxr_transaction_unavailable');
        try {
            $existing->execute(['site'=>$siteId,'fingerprint'=>$fingerprint]);
            $prior=$existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($prior)) {
                if (($prior['status']??'')!=='completed') {
                    throw new RuntimeException('tenant_wxr_job_requires_review');
                }
                $stats=json_decode((string)$prior['stats_json'],true);
                if (!is_array($stats))throw new RuntimeException('tenant_wxr_receipt_invalid');
                $pdo->commit();
                return $stats+['replayed'=>true];
            }
            $insert=$pdo->prepare(
                "INSERT INTO cms_site_import_jobs(site_id,source_type,source_fingerprint," .
                "created_by,status,options_json) VALUES(:site,'wordpress_wxr',:fingerprint,:actor,'processing',:options)"
            );
            $insert->execute([
                'site'=>$siteId,'fingerprint'=>$fingerprint,'actor'=>$actorId,
                'options'=>json_encode([
                    'locale'=>$locale,'source_sha256'=>$sourceHash,
                    'publish_policy'=>'draft_only','attachments'=>'skip_no_download',
                    'redirects'=>'not_created'
                ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
            ]);
            $jobId=(int)$pdo->lastInsertId();
            $stats=[
                'job_id'=>$jobId,'site_id'=>$siteId,'scanned'=>0,'created'=>0,
                'skipped'=>0,'attachments_skipped'=>0,'terms_created'=>0,
                'published'=>0,'redirects_created'=>0,'review_required'=>true,
                'source_sha256'=>$sourceHash
            ];
            $seen=[];
            foreach ($xml->channel->item as $item) {
                $stats['scanned']++;
                if ($stats['scanned']>self::MAX_ITEMS) {
                    throw new RuntimeException('tenant_wxr_item_limit');
                }
                $wp=$item->children((string)$namespace['wp']);
                $postId=trim((string)($wp->post_id??''));
                $kind=trim((string)($wp->post_type??'post'));
                if ($kind==='attachment') {
                    $stats['attachments_skipped']++;
                    $stats['skipped']++;
                    continue;
                }
                if ($postId==='' || strlen($postId)>150 || !ctype_digit($postId) ||
                    isset($seen[$postId]) || !in_array($kind,['post','page'],true)) {
                    $stats['skipped']++;
                    continue;
                }
                $seen[$postId]=true;
                $content=$item->children((string)($namespace['content']??''));
                $rawBody=(string)($content->encoded??'');
                $text=mb_substr(trim(strip_tags($rawBody)),0,1_000_000);
                self::ensureContentType($pdo,$siteId,$kind);
                $title=mb_substr(trim(strip_tags((string)$item->title)),0,255);
                if ($title==='')$title='Imported '.$kind.' '.$postId;
                $slug=self::slug(
                    $pdo,$siteId,$locale,(string)($wp->post_name??''),$postId
                );

                $meta=self::metadata($wp);
                $metaTitle=mb_substr(trim(strip_tags(
                    (string)($meta['_yoast_wpseo_title']??$title)
                )),0,255);
                $metaDesc=mb_substr(trim(strip_tags(
                    (string)($meta['_yoast_wpseo_metadesc']??'')
                )),0,320);
                $blocks=BlockEditor::encode(BlockEditor::legacy($text));
                $new=$pdo->prepare(
                    "INSERT INTO cms_pages(site_id,slug,locale,title,body,status,is_home," .
                    "author_id,meta_title,meta_description,featured_image,content_type," .
                    "robots_index,robots_follow,schema_type,block_schema_version," .
                    "block_document,trash_status,editorial_state,source_provider,source_ref) " .
                    "VALUES(:site,:slug,:locale,:title,:body,'draft',0,:actor," .
                    ":meta_title,:meta_desc,'',:kind,0,0,:schema,1,:blocks," .
                    "'active','draft','wordpress',:source_id)"
                );
                $new->execute([
                    'site'=>$siteId,'slug'=>$slug,'locale'=>$locale,
                    'title'=>$title,'body'=>$text,'actor'=>$actorId,
                    'meta_title'=>$metaTitle,'meta_desc'=>$metaDesc,
                    'kind'=>$kind,'schema'=>$kind==='post'?'Article':'WebPage',
                    'blocks'=>$blocks,'source_id'=>$postId
                ]);
                $pageId=(int)$pdo->lastInsertId();
                $link=trim((string)($item->link??''));
                $parts=parse_url($link);
                if (strlen($link)>1000 || !is_array($parts) ||
                    !in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true) ||
                    empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                    $link='';
                }
                $map=$pdo->prepare(
                    'INSERT INTO cms_site_import_items(site_id,job_id,source_post_id,' .
                    'target_page_id,original_url) VALUES(:site,:job,:post,:page,:url)'
                );
                $map->execute([
                    'site'=>$siteId,'job'=>$jobId,'post'=>$postId,
                    'page'=>$pageId,'url'=>$link
                ]);
                self::importTerms($pdo,$siteId,$pageId,$item,$stats);
                $stats['created']++;
            }
            $finish=$pdo->prepare(
                "UPDATE cms_site_import_jobs SET status='completed',stats_json=:stats," .
                "finished_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP " .
                "WHERE id=:job AND site_id=:site AND status='processing'"
            );
            $finish->execute([
                'stats'=>json_encode($stats,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'job'=>$jobId,'site'=>$siteId
            ]);
            if ($finish->rowCount()!==1)throw new RuntimeException('tenant_wxr_job_lost');
            $pdo->commit();
            return $stats+['replayed'=>false];
        } catch(Throwable $error) {
            if ($pdo->inTransaction())$pdo->rollBack();
            throw $error;
        }
    }

    private static function authorize(PDO $pdo,int $siteId,int $actorId): void
    {
        if ($siteId<1 || $actorId<1 || !SiteScopeMode::active($pdo)) {
            throw new RuntimeException('tenant_wxr_scope_unavailable');
        }
        $q=$pdo->prepare(
            'SELECT m.site_role FROM cms_site_memberships m ' .
            'JOIN users u ON u.id=m.user_id JOIN cms_sites s ON s.id=m.site_id ' .
            "WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active' " .
            "AND s.status IN('active','staging') LIMIT 1"
        );
        $q->execute(['site'=>$siteId,'actor'=>$actorId]);
        if (!in_array($q->fetchColumn(),['owner','admin','editor'],true)) {
            throw new RuntimeException('tenant_access_denied');
        }
    }

    private static function assertSchema(PDO $pdo): void
    {
        try {
            $pdo->query('SELECT site_id,source_fingerprint,created_by,status ' .
                'FROM cms_site_import_jobs WHERE 1=0');
            $pdo->query('SELECT site_id,job_id,source_post_id,target_page_id ' .
                'FROM cms_site_import_items WHERE 1=0');
            $target=SiteUniqueKeys::target(
                $pdo,'cms_site_import_jobs','source_fingerprint'
            );
            if ($target!=='(site_id,source_fingerprint)') {
                throw new RuntimeException('tenant_wxr_ledger_schema_unavailable');
            }
        } catch(Throwable) {
            throw new RuntimeException('tenant_wxr_ledger_schema_unavailable');
        }
    }

    private static function ensureContentType(PDO $pdo,int $siteId,string $key): void
    {
        $q=$pdo->prepare(
            'SELECT 1 FROM cms_content_types WHERE site_id=:site AND content_key=:key'
        );
        $q->execute(['site'=>$siteId,'key'=>$key]);
        if($q->fetchColumn())return;
        $q=$pdo->prepare(
            'INSERT INTO cms_content_types(site_id,content_key,label,singular_label,' .
            'public,hierarchical,supports_json) ' .
            "VALUES(:site,:key,:label,:singular,1,:hier,'[\"title\",\"editor\",\"revisions\"]')"
        );
        $q->execute([
            'site'=>$siteId,'key'=>$key,
            'label'=>$key==='post'?'Posts':'Pages',
            'singular'=>$key==='post'?'Post':'Page',
            'hier'=>$key==='page'?1:0
        ]);
    }

    private static function metadata(SimpleXMLElement $wp): array
    {
        $out=[];
        foreach ($wp->postmeta??[] as $meta) {
            $k=(string)($meta->meta_key??'');
            if (in_array($k,['_yoast_wpseo_title','_yoast_wpseo_metadesc'],true)) {
                $out[$k]=(string)($meta->meta_value??'');
            }
        }
        return $out;
    }

    private static function slug(
        PDO $pdo,int $siteId,string $locale,string $original,string $sourceId
    ): string {
        $base=strtolower(trim($original));
        $base=preg_replace('/[^a-z0-9-]+/','-',$base)??'';
        $base=trim($base,'-');
        if ($base==='')$base='wp-'.$sourceId;
        $base=substr($base,0,165);
        $q=$pdo->prepare(
            'SELECT 1 FROM cms_pages WHERE site_id=:site AND slug=:slug ' .
            'AND locale=:locale LIMIT 1'
        );
        for ($i=0;$i<100;$i++) {
            $slug=$i===0?$base:substr($base,0,160).'-'.($i===1?'wp-'.$sourceId:'wp-'.$sourceId.'-'.$i);
            $slug=substr($slug,0,189);
            $q->execute(['site'=>$siteId,'slug'=>$slug,'locale'=>$locale]);
            if (!$q->fetchColumn())return $slug;
        }
        throw new RuntimeException('tenant_wxr_slug_conflict');
    }

    private static function importTerms(
        PDO $pdo,int $siteId,int $pageId,SimpleXMLElement $item,array &$stats
    ): void {
        foreach ($item->category??[] as $category) {
            $domain=(string)($category['domain']??'category');
            if (!in_array($domain,['category','post_tag'],true))continue;
            $taxonomy=$domain==='post_tag'?'tag':'category';
            $slug=strtolower(trim((string)($category['nicename']??'')));
            $slug=trim(preg_replace('/[^a-z0-9-]/','-',$slug)??'','-');
            $name=mb_substr(trim(strip_tags((string)$category)),0,255);
            if ($slug==='' || $name==='')continue;

            $q=$pdo->prepare(
                'SELECT id FROM cms_taxonomies WHERE site_id=:site ' .
                'AND taxonomy_key=:key LIMIT 1'
            );
            $q->execute(['site'=>$siteId,'key'=>$taxonomy]);
            $taxId=$q->fetchColumn();
            if (!$taxId) {
                $create=$pdo->prepare(
                    'INSERT INTO cms_taxonomies(site_id,taxonomy_key,label,singular_label,' .
                    'hierarchical,public) VALUES(:site,:key,:label,:label2,:hier,1)'
                );
                $create->execute([
                    'site'=>$siteId,'key'=>$taxonomy,'label'=>$taxonomy,
                    'label2'=>$taxonomy,'hier'=>$taxonomy==='category'?1:0
                ]);
                $taxId=$pdo->lastInsertId();
            }
            $term=$pdo->prepare(
                'SELECT id FROM cms_terms WHERE site_id=:site AND taxonomy_id=:tax ' .
                'AND slug=:slug LIMIT 1'
            );
            $term->execute(['site'=>$siteId,'tax'=>$taxId,'slug'=>$slug]);
            $termId=$term->fetchColumn();
            if (!$termId) {
                $add=$pdo->prepare(
                    'INSERT INTO cms_terms(site_id,taxonomy_id,slug,name,description) ' .
                    "VALUES(:site,:tax,:slug,:name,'')"
                );
                $add->execute([
                    'site'=>$siteId,'tax'=>$taxId,'slug'=>$slug,'name'=>$name
                ]);
                $termId=$pdo->lastInsertId();
                $stats['terms_created']++;
            }
            $rel=$pdo->prepare(
                'INSERT INTO cms_term_relationships(site_id,page_id,term_id,position) ' .
                'VALUES(:site,:page,:term,0) ON CONFLICT(page_id,term_id) DO NOTHING'
            );
            $rel->execute([
                'site'=>$siteId,'page'=>$pageId,'term'=>$termId
            ]);
        }
    }
}
