<?php
declare(strict_types=1);
namespace VazinCMS;

final class ResponseCache
{
    public static function etag(string $body): string{return '"'.hash('sha256',$body).'"';}
    public static function conditional(string $body,?int $modifiedAt=null,int $maxAge=300): bool
    {
        $etag=self::etag($body);header('ETag: '.$etag);header('Cache-Control: public, max-age='.max(0,$maxAge));if($modifiedAt){header('Last-Modified: '.gmdate('D, d M Y H:i:s',$modifiedAt).' GMT');}
        $ifNone=(string)($_SERVER['HTTP_IF_NONE_MATCH']??'');if($ifNone!==''&&hash_equals($etag,$ifNone)){http_response_code(304);return true;}
        if($modifiedAt&&($since=(string)($_SERVER['HTTP_IF_MODIFIED_SINCE']??''))!==''){$ts=strtotime($since);if($ts!==false&&$ts>=$modifiedAt){http_response_code(304);return true;}}
        return false;
    }
}
