<?php
declare(strict_types=1);
namespace VazinCMS;

final class LocalReturn
{
    public static function path(mixed $value,string $fallback='/admin'):string
    {
        if(!is_string($value)||$value===''||strlen($value)>1024)return$fallback;
        if(preg_match('/[\x00-\x1f\x7f]/',$value)===1||str_contains($value,'\\')||str_contains($value,'%'))return$fallback;
        $parts=parse_url($value);
        if($parts===false||isset($parts['scheme'])||isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))return$fallback;
        $path=(string)($parts['path']??'');
        if(!str_starts_with($path,'/')||str_starts_with($path,'//')||str_contains($path,'//'))return$fallback;
        foreach(explode('/',$path)as$segment)if($segment==='.'||$segment==='..')return$fallback;
        $route=strtolower(rtrim($path,'/'))?:'/';
        if($route==='/auth'||str_starts_with($route,'/auth/')||in_array($route,['/login','/logout','/install','/admin/login'],true))return$fallback;
        return$value;
    }
}
