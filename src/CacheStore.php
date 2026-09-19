<?php
declare(strict_types=1);
namespace VazinCMS;

final class CacheStore
{
    private static ?object $redis=null;
    private static array $memory=[];
    public static function get(string $key): mixed
    {
        $raw=self::rawGet($key);if($raw===null){self::metric('miss');return null;}self::metric('hit');$decoded=json_decode($raw,true);return is_array($decoded)&&array_key_exists('v',$decoded)?$decoded['v']:null;
    }
    public static function set(string $key,mixed $value,int $ttl=300,array $tags=[]): void
    {
        $payload=json_encode(['v'=>$value],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);self::rawSet($key,$payload,max(1,$ttl));foreach(array_unique($tags) as $tag)self::tag((string)$tag,$key,$ttl);
    }
    public static function delete(string $key): void{self::rawDelete($key);self::metric('eviction');}
    public static function invalidateTag(string $tag): int
    {
        $keys=self::get('tag:'.$tag);$count=0;if(is_array($keys))foreach($keys as$key){self::rawDelete((string)$key);$count++;}self::rawDelete('tag:'.$tag);if($count)self::metric('eviction',$count);return$count;
    }
    private static function redis(): ?object
    {
        if(self::$redis!==null)return self::$redis;if(!class_exists('Redis'))return null;try{$r=new \Redis();$host=(string)(getenv('REDIS_HOST')?:'127.0.0.1');$port=(int)(getenv('REDIS_PORT')?:6379);if(!$r->connect($host,$port,0.3))return null;$r->setOption(\Redis::OPT_PREFIX,'vazincms:');return self::$redis=$r;}catch(\Throwable){return null;}
    }
    private static function path(string $key): string
    {
        $dir=RuntimePaths::storage().'/cache';if(!is_dir($dir))@mkdir($dir,0700,true);return$dir.'/'.hash('sha256',$key).'.json';
    }
    private static function rawGet(string $key): ?string
    {
        if(isset(self::$memory[$key])&&self::$memory[$key]['e']>=time())return self::$memory[$key]['v'];if($r=self::redis()){$v=$r->get($key);return is_string($v)?$v:null;}$path=self::path($key);if(!is_file($path))return null;$d=json_decode((string)file_get_contents($path),true);if(!is_array($d)||($d['e']??0)<time()){@unlink($path);return null;}return(string)($d['v']??'');
    }
    private static function rawSet(string $key,string $value,int $ttl): void
    {
        self::$memory[$key]=['v'=>$value,'e'=>time()+$ttl];if($r=self::redis()){$r->setex($key,$ttl,$value);return;}file_put_contents(self::path($key),json_encode(['e'=>time()+$ttl,'v'=>$value],JSON_UNESCAPED_SLASHES),LOCK_EX);
    }
    private static function rawDelete(string $key): void
    {
        unset(self::$memory[$key]);if($r=self::redis()){$r->del($key);return;}@unlink(self::path($key));
    }
    private static function tag(string $tag,string $key,int $ttl): void
    {
        $tagKey='tag:'.$tag;$keys=self::get($tagKey);if(!is_array($keys))$keys=[];if(!in_array($key,$keys,true))$keys[]=$key;self::rawSet($tagKey,json_encode(['v'=>$keys],JSON_UNESCAPED_SLASHES),$ttl);
    }
    private static function metric(string $kind,int $amount=1): void
    {
        try{$pdo=Database::connection();$driver=(string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);$column=match($kind){'hit'=>'hits','miss'=>'misses','eviction'=>'evictions'};$time=match($kind){'hit'=>'last_hit_at','miss'=>'last_miss_at',default=>null};if($driver==='sqlite'){$sql="INSERT INTO cms_cache_metrics(metric_key,$column".($time?",$time":'').") VALUES('default',:amount".($time?',CURRENT_TIMESTAMP':'').") ON CONFLICT(metric_key) DO UPDATE SET $column=$column+:amount2,updated_at=CURRENT_TIMESTAMP".($time?",$time=CURRENT_TIMESTAMP":'');$pdo->prepare($sql)->execute(['amount'=>$amount,'amount2'=>$amount]);}else{$sql="INSERT INTO cms_cache_metrics(metric_key,$column".($time?",$time":'').") VALUES('default',:amount".($time?',CURRENT_TIMESTAMP':'').") ON CONFLICT(metric_key) DO UPDATE SET $column=cms_cache_metrics.$column+:amount2,updated_at=CURRENT_TIMESTAMP".($time?",$time=CURRENT_TIMESTAMP":'');$pdo->prepare($sql)->execute(['amount'=>$amount,'amount2'=>$amount]);}}catch(\Throwable){}
    }
}
