<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class RedirectManager
{
    public static function save(PDO $pdo,string $source,string $target,int $code,int $actorId): void
    {
        $source='/'.ltrim(trim($source),'/');
        if(!preg_match('#^/[A-Za-z0-9/_\-.]{1,498}$#',$source))throw new InvalidArgumentException('Invalid redirect source.');
        if(!in_array($code,[301,302,307,308],true))throw new InvalidArgumentException('Invalid redirect status.');
        $validTarget=str_starts_with($target,'/')||(filter_var($target,FILTER_VALIDATE_URL)&&preg_match('#^https://#i',$target));if(!$validTarget)throw new InvalidArgumentException('Invalid redirect target.');
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$pdo->prepare('INSERT INTO cms_redirects(source_path,target_url,status_code,created_by) VALUES(:source,:target,:code,:actor) ON CONFLICT(source_path) DO UPDATE SET target_url=:target2,status_code=:code2,updated_at=CURRENT_TIMESTAMP')->execute(['source'=>$source,'target'=>$target,'code'=>$code,'actor'=>$actorId,'target2'=>$target,'code2'=>$code]);
        else $pdo->prepare('INSERT INTO cms_redirects(source_path,target_url,status_code,created_by) VALUES(:source,:target,:code,:actor) ON CONFLICT(source_path) DO UPDATE SET target_url=EXCLUDED.target_url,status_code=EXCLUDED.status_code,updated_at=CURRENT_TIMESTAMP')->execute(['source'=>$source,'target'=>$target,'code'=>$code,'actor'=>$actorId]);
    }

    public static function resolve(PDO $pdo,string $path): ?array
    {
        $q=$pdo->prepare('SELECT * FROM cms_redirects WHERE source_path=:path LIMIT 1');$q->execute(['path'=>$path]);$row=$q->fetch();if(!$row)return null;$pdo->prepare('UPDATE cms_redirects SET hits=hits+1,last_hit_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$row['id']]);return$row;
    }
}
