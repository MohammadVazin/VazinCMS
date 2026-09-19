<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;

final class SiteContext
{
    private static ?array $current=null;
    public static function resolve(PDO $pdo,?string $host=null): array
    {
        if(self::$current!==null)return self::$current;$host=strtolower(trim((string)($host??($_SERVER['HTTP_HOST']??''))));$host=preg_replace('/:\d+$/','',$host)??$host;
        if($host!==''){$q=$pdo->prepare("SELECT s.* FROM cms_sites s JOIN cms_site_domains d ON d.site_id=s.id WHERE lower(d.domain)=:host AND d.verified_at IS NOT NULL AND s.status IN('active','staging') ORDER BY d.is_primary DESC LIMIT 1");$q->execute(['host'=>$host]);if($r=$q->fetch())return self::$current=$r;}
        $r=$pdo->query("SELECT * FROM cms_sites WHERE site_key='default' LIMIT 1")->fetch();return self::$current=is_array($r)?$r:['id'=>1,'site_key'=>'default','name'=>'Default Site','status'=>'active','default_locale'=>'en','settings_json'=>'{}'];
    }
    public static function id(): int{return(int)(self::$current['id']??1);}
    public static function key(): string{return(string)(self::$current['site_key']??'default');}
    public static function cacheKey(string $key): string{return'site:'.self::key().':'.$key;}
    public static function reset(): void{self::$current=null;}
}
