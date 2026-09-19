<?php
declare(strict_types=1);
namespace VazinCMS\Controllers;

use PDO;
use VazinCMS\Database;
use VazinCMS\View;

final class PublicTravelController
{
    private const LOCALES=['fa','ar','en','ru','tr','hy','kk','tg','zh'];
    private const TYPE_MAP=['destinations'=>'destination','services'=>'service','articles'=>'article'];

    public function home(?string $locale): void
    {
        $locale=$this->locale($locale); $pdo=Database::connection();
        $destinations=$this->items($pdo,$locale,'destination',6);
        $services=$this->items($pdo,$locale,'service',6);
        $articles=$this->items($pdo,$locale,'article',6);
        $page=$this->find($pdo,$locale,'page','home');
        View::renderPublic('home',['locale'=>$locale,'page'=>$page,'destinations'=>$destinations,'services'=>$services,'articles'=>$articles,'meta'=>$this->meta($page,'Vazin Travel','خدمات سفر، مقصدها و راهنماهای کاربردی')]);
    }

    public function listing(string $locale,string $section): void
    {
        $locale=$this->locale($locale); $type=self::TYPE_MAP[$section]??'article';
        $items=$this->items(Database::connection(),$locale,$type,100);
        $labels=['destinations'=>'مقصدها','services'=>'خدمات سفر','articles'=>'راهنما و مقاله'];
        View::renderPublic('listing',['locale'=>$locale,'section'=>$section,'items'=>$items,'heading'=>$labels[$section],'meta'=>$this->meta(null,$labels[$section].' | Vazin Travel','محتوای منتشرشده و به‌روز Vazin Travel')]);
    }

    public function detail(string $locale,string $type,string $slug): void
    {
        $locale=$this->locale($locale); $item=$this->find(Database::connection(),$locale,$type,$slug);
        if(!$item){http_response_code(404);View::renderPublic('not-found',['locale'=>$locale,'meta'=>$this->meta(null,'صفحه پیدا نشد | Vazin Travel','')]);return;}
        View::renderPublic('detail',['locale'=>$locale,'item'=>$item,'meta'=>$this->meta($item)]);
    }

    public function sitemap(): void
    {
        $rows=Database::connection()->query("SELECT locale,content_type,slug,updated_at FROM travel_contents WHERE site_key='travel' AND status='published' ORDER BY id")->fetchAll();
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        echo '<url><loc>https://travel.vazin.online/</loc></url>'."\n";
        foreach($rows as $r){$url='https://travel.vazin.online/'.rawurlencode($r['locale']).'/'.rawurlencode($r['content_type']).'/'.rawurlencode($r['slug']);echo '<url><loc>'.htmlspecialchars($url,ENT_XML1).'</loc><lastmod>'.gmdate('c',strtotime((string)$r['updated_at'])).'</lastmod></url>'."\n";}
        echo '</urlset>';
    }

    public function feed(): void
    {
        $items=$this->items(Database::connection(),'fa','article',30);header('Content-Type: application/rss+xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Vazin Travel</title><link>https://travel.vazin.online/fa/articles</link><description>آخرین راهنماهای سفر</description>';
        foreach($items as $i){$url='https://travel.vazin.online/fa/article/'.rawurlencode($i['slug']);echo '<item><title>'.htmlspecialchars($i['title'],ENT_XML1).'</title><link>'.$url.'</link><guid>'.$url.'</guid><description>'.htmlspecialchars((string)$i['excerpt'],ENT_XML1).'</description></item>';}
        echo '</channel></rss>';
    }

    public function robots(): void {header('Content-Type: text/plain; charset=utf-8');echo "User-agent: *\nAllow: /\nSitemap: https://travel.vazin.online/sitemap.xml\n";}

    private function items(PDO $pdo,string $locale,string $type,int $limit): array{$s=$pdo->prepare("SELECT * FROM travel_contents WHERE site_key='travel' AND status='published' AND locale=:locale AND content_type=:type ORDER BY sort_order,id DESC LIMIT ".(int)$limit);$s->execute(['locale'=>$locale,'type'=>$type]);return$s->fetchAll();}
    private function find(PDO $pdo,string $locale,string $type,string $slug): array|false{$s=$pdo->prepare("SELECT * FROM travel_contents WHERE site_key='travel' AND status='published' AND locale=:locale AND content_type=:type AND slug=:slug LIMIT 1");$s->execute(compact('locale','type','slug'));return$s->fetch();}
    private function locale(?string $locale): string {if($locale&&in_array($locale,['fa','ru','en'],true))return$locale;return \VazinCMS\UiLocale::detect();}
    private function meta(array|false|null $item,?string $title=null,string $description=''): array{return['title'=>$title?:((string)($item['meta_title']??$item['title']??'Vazin Travel')),'description'=>$description!==''?$description:(string)($item['meta_description']??$item['excerpt']??''),'image'=>(string)($item['featured_image']??''),'canonical'=>'https://travel.vazin.online'.(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/')];}
}
