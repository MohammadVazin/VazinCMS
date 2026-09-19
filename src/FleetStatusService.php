<?php
declare(strict_types=1);
namespace VazinCMS;
final class FleetStatusService
{
    private const SITES = [['label'=>'مرکز VazinCMS','host'=>'cms.vazin.online'],['label'=>'Vazin Studio','host'=>'studio.vazin.online'],['label'=>'Vazin Travel','host'=>'travel.vazin.online'],['label'=>'Vazin Visa','host'=>'visa.vazin.online'],['label'=>'روسیه فارسی','host'=>'russiafa.ru'],['label'=>'Vazin Learn','host'=>'learn.vazin.online']];
    /** Fixed first-party endpoints only; this is not a proxy and accepts no URL input. */
    public static function summary(?callable $fetch = null): array
    {
        $expected=Version::current();$fetch ??= self::fetch(...);$sites=[];
        foreach(self::SITES as $site){$body=$fetch($site['host']);$health=is_string($body)?json_decode($body,true):null;$version=is_array($health)&&is_string($health['version']??null)?$health['version']:null;$healthy=is_array($health)&&($health['ok']??false)===true&&($health['service']??null)==='VazinCMS'&&$version===$expected;$sites[]=$site+['version'=>$version,'status'=>$healthy?'healthy':'attention','label_status'=>$healthy?'به‌روز و سالم':'نیازمند بررسی'];}
        return ['expected_version'=>$expected,'sites'=>$sites];
    }
    private static function fetch(string $host): ?string
    {
        if(!in_array($host,array_column(self::SITES,'host'),true))return null;$handle=curl_init('https://'.$host.'/health');if($handle===false)return null;
        curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>1500,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'']);$body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);return $status===200&&is_string($body)?$body:null;
    }
}
