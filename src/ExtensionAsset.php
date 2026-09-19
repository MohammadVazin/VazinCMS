<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;

final class ExtensionAsset
{
    private const TYPES = [
        'css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8','mjs'=>'application/javascript; charset=utf-8',
        'svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif',
        'ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf',
    ];

    public static function url(string $type,string $key,string $relative):string
    {
        self::validate($relative);
        $prefix=$type==='theme'?'theme-assets':'module-assets';
        if(!in_array($type,ExtensionManifest::TYPES,true)||preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/',$key)!==1)throw new RuntimeException('شناسهٔ asset معتبر نیست.');
        return'/'.$prefix.'/'.rawurlencode($key).'/'.implode('/',array_map('rawurlencode',explode('/',$relative)));
    }

    public static function serve(string $type,string $key,string $relative):void
    {
        try{
            self::validate($relative);
            $row=ExtensionManager::activeExtension($type,$key);
            if(!$row)throw new RuntimeException('inactive');
            $manifest=ExtensionManager::manifestForRow($row);
            $root=realpath($manifest->directory().'/assets');
            if($root===false||!is_dir($root)||is_link($root))throw new RuntimeException('missing');
            $cursor=$root;
            foreach(explode('/',$relative)as$part){$cursor.=DIRECTORY_SEPARATOR.$part;if(is_link($cursor))throw new RuntimeException('link');}
            $file=realpath($cursor);$extension=strtolower((string)pathinfo((string)$file,PATHINFO_EXTENSION));
            if($file===false||!is_file($file)||is_link($file)||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!isset(self::TYPES[$extension]))throw new RuntimeException('missing');
            header('X-Content-Type-Options: nosniff');header('Content-Type: '.self::TYPES[$extension]);header('Content-Length: '.filesize($file));header('Cache-Control: public, max-age=31536000, immutable');readfile($file);
        }catch(\Throwable){http_response_code(404);}
    }

    private static function validate(string $relative):void
    {
        if($relative===''||strlen($relative)>500||str_contains($relative,'\\')||preg_match('#(^/|(^|/)\.\.?(/|$)|[^A-Za-z0-9._/-]|//)#',$relative)===1)throw new RuntimeException('مسیر asset معتبر نیست.');
    }
}
