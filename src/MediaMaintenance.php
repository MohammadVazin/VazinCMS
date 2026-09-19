<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;

final class MediaMaintenance
{
    public static function orphanFiles(PDO $pdo): array
    {
        $known=[];
        foreach($pdo->query('SELECT stored_name FROM cms_media')->fetchAll(PDO::FETCH_COLUMN) as $n)$known[(string)$n]=true;
        foreach($pdo->query('SELECT stored_name FROM cms_media_derivatives')->fetchAll(PDO::FETCH_COLUMN) as $n)$known[(string)$n]=true;
        $out=[];$dir=RuntimePaths::uploads();
        foreach(glob($dir.'/*')?:[] as $path){if(is_file($path)&&!isset($known[basename($path)]))$out[]=basename($path);}
        sort($out,SORT_STRING);return$out;
    }

    public static function removeOrphans(PDO $pdo,bool $apply=false): array
    {
        $items=self::orphanFiles($pdo);$removed=[];
        if($apply)foreach($items as $name){$path=RuntimePaths::uploads().'/'.$name;if(is_file($path)&&unlink($path))$removed[]=$name;}
        return ['orphans'=>$items,'removed'=>$removed,'dry_run'=>!$apply];
    }
}
