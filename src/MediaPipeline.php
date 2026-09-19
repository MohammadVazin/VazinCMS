<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;

final class MediaPipeline
{
    public static function inspect(string $path,string $mime): array
    {
        $hash=hash_file('sha256',$path)?:'';$width=null;$height=null;
        if(str_starts_with($mime,'image/')){$size=@getimagesize($path);if(is_array($size)){[$width,$height]=$size;}}
        return ['sha256'=>$hash,'width'=>$width,'height'=>$height];
    }

    public static function duplicate(PDO $pdo,string $sha256): ?int
    {
        if($sha256==='')return null;$q=$pdo->prepare('SELECT id FROM cms_media WHERE sha256=:sha LIMIT 1');$q->execute(['sha'=>$sha256]);$id=$q->fetchColumn();return$id===false?null:(int)$id;
    }

    public static function publicUrl(string $storedName): string
    {
        $base=rtrim((string)getenv('MEDIA_CDN_URL'),'/');$path='/uploads/'.rawurlencode($storedName);return $base!==''?$base.$path:$path;
    }
    public static function derivatives(string $source,string $mime,string $storedName): array
    {
        if(!str_starts_with($mime,'image/')||!function_exists('imagecreatefromstring'))return [];
        $bytes=@file_get_contents($source);$image=$bytes!==false?@imagecreatefromstring($bytes):false;if(!$image)return [];
        $sw=imagesx($image);$sh=imagesy($image);$dir=dirname($source);$base=pathinfo($storedName,PATHINFO_FILENAME);$out=[];
        foreach([320,768,1280] as $target){if($sw<=$target)continue;$ratio=$target/$sw;$h=max(1,(int)round($sh*$ratio));$canvas=imagecreatetruecolor($target,$h);imagealphablending($canvas,false);imagesavealpha($canvas,true);imagecopyresampled($canvas,$image,0,0,0,0,$target,$h,$sw,$sh);$name=$base.'-'.$target.'.webp';$path=$dir.'/'.$name;if(function_exists('imagewebp')&&imagewebp($canvas,$path,82))$out[]=['variant'=>'w'.$target,'stored_name'=>$name,'mime_type'=>'image/webp','width'=>$target,'height'=>$h,'file_size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];imagedestroy($canvas);}
        imagedestroy($image);return $out;
    }

    public static function srcset(array $derivatives): string
    {
        $items=[];foreach($derivatives as $d){if(!empty($d['width']))$items[]=self::publicUrl((string)$d['stored_name']).' '.(int)$d['width'].'w';}return implode(', ',$items);
    }
}
