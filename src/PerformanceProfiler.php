<?php
declare(strict_types=1);
namespace VazinCMS;

final class PerformanceProfiler
{
    public static function sample(string $key,string $path,float $started,string $cacheStatus='none'): void
    {
        try{$duration=(int)round((microtime(true)-$started)*1000);$memory=memory_get_peak_usage(true);Database::connection()->prepare('INSERT INTO cms_performance_samples(sample_key,path,duration_ms,memory_peak,cache_status) VALUES(:key,:path,:duration,:memory,:cache)')->execute(['key'=>$key,'path'=>$path,'duration'=>$duration,'memory'=>$memory,'cache'=>$cacheStatus]);}catch(\Throwable){}
    }
}
