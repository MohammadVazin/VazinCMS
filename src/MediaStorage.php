<?php
declare(strict_types=1);
namespace VazinCMS;

use RuntimeException;

final class MediaStorage
{
    public static function driver(): string
    {
        $driver=strtolower(trim((string)(getenv('MEDIA_STORAGE_DRIVER')?:'local')));
        if(!in_array($driver,['local','s3'],true))throw new RuntimeException('Unsupported media storage driver.');
        return $driver;
    }

    public static function localPath(string $key): string
    {
        if(self::driver()!=='local')throw new RuntimeException('Local path unavailable for remote storage.');
        if(!preg_match('/^[a-zA-Z0-9._-]+$/',$key))throw new RuntimeException('Unsafe media storage key.');
        return RuntimePaths::uploads().'/'.$key;
    }

    public static function publicUrl(string $key): string{return MediaPipeline::publicUrl($key);}
}
