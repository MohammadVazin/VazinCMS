<?php
declare(strict_types=1);
namespace VazinCMS;
use PDO; use InvalidArgumentException;
final class NoCodeAppBuilder
{
    public static function create(PDO $pdo,int $siteId,array $definition): int
    {
        self::validateDefinition($definition);$pdo->prepare("INSERT INTO cms_apps(site_id,app_key,label,status,navigation_json,settings_json) VALUES(:site,:key,:label,'active',:nav,:settings)")->execute(['site'=>$siteId,'key'=>$definition['app_key'],'label'=>$definition['label'],'nav'=>json_encode($definition['navigation']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'settings'=>json_encode($definition['settings']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$app=(int)$pdo->lastInsertId();
        $e=$pdo->prepare('INSERT INTO cms_app_entities(app_id,entity_key,label,plural_label,storage_type,content_type_key,form_key,workflow_key,permissions_json,settings_json) VALUES(:app,:key,:label,:plural,:storage,:content,:form,:workflow,:permissions,:settings)');
        foreach($definition['entities'] as$row)$e->execute(['app'=>$app,'key'=>$row['key'],'label'=>$row['label'],'plural'=>$row['plural_label'],'storage'=>$row['storage_type']??'content','content'=>$row['content_type_key']??null,'form'=>$row['form_key']??null,'workflow'=>$row['workflow_key']??null,'permissions'=>json_encode($row['permissions']??[]),'settings'=>json_encode($row['settings']??[])]);
        $r=$pdo->prepare('INSERT INTO cms_app_relations(app_id,relation_key,source_entity_key,target_entity_key,relation_type,settings_json) VALUES(:app,:key,:source,:target,:type,:settings)');foreach($definition['relations']??[] as$row)$r->execute(['app'=>$app,'key'=>$row['key'],'source'=>$row['source'],'target'=>$row['target'],'type'=>$row['type'],'settings'=>json_encode($row['settings']??[])]);
        $v=$pdo->prepare('INSERT INTO cms_app_views(app_id,view_key,entity_key,view_type,columns_json,filters_json,sort_json,settings_json) VALUES(:app,:key,:entity,:type,:columns,:filters,:sort,:settings)');foreach($definition['views']??[] as$row)$v->execute(['app'=>$app,'key'=>$row['key'],'entity'=>$row['entity'],'type'=>$row['type'],'columns'=>json_encode($row['columns']??[]),'filters'=>json_encode($row['filters']??[]),'sort'=>json_encode($row['sort']??[]),'settings'=>json_encode($row['settings']??[])]);
        $rt=$pdo->prepare('INSERT INTO cms_app_routes(app_id,route_key,path,view_key,permission_key) VALUES(:app,:key,:path,:view,:permission)');foreach($definition['routes']??[] as$row)$rt->execute(['app'=>$app,'key'=>$row['key'],'path'=>$row['path'],'view'=>$row['view'],'permission'=>$row['permission']??'']);return$app;
    }
    public static function export(PDO $pdo,int$appId): array
    {
        $a=$pdo->prepare('SELECT * FROM cms_apps WHERE id=:id');$a->execute(['id'=>$appId]);$app=$a->fetch();if(!$app)throw new InvalidArgumentException('App not found.');$get=fn(string$table)=>$pdo->query('SELECT * FROM '.$table.' WHERE app_id='.(int)$appId.' ORDER BY id')->fetchAll();return['app'=>$app,'entities'=>$get('cms_app_entities'),'relations'=>$get('cms_app_relations'),'views'=>$get('cms_app_views'),'routes'=>$get('cms_app_routes')];
    }
    public static function route(PDO $pdo,int$siteId,string$path): ?array
    {
        $q=$pdo->prepare("SELECT r.*,a.app_key,a.label app_label,a.status app_status,v.* FROM cms_app_routes r JOIN cms_apps a ON a.id=r.app_id JOIN cms_app_views v ON v.app_id=a.id AND v.view_key=r.view_key WHERE a.site_id=:site AND a.status='active' AND r.path=:path LIMIT 1");$q->execute(['site'=>$siteId,'path'=>$path]);$r=$q->fetch();return is_array($r)?$r:null;
    }
    public static function buildQuery(array $allowed,array $filters,array $sort): array
    {
        $where=[];$args=[];$i=0;foreach($filters as$f){$field=(string)($f['field']??'');$op=(string)($f['op']??'eq');if(!in_array($field,$allowed,true)||!in_array($op,['eq','neq','contains','gt','gte','lt','lte'],true))throw new InvalidArgumentException('Unsafe query filter.');$param='p'.$i++;$sqlField='"'.str_replace('"','',$field).'"';$where[]=match($op){'eq'=>$sqlField.'=:'.$param,'neq'=>$sqlField.'<>:'.$param,'contains'=>'LOWER('.$sqlField.') LIKE LOWER(:'.$param.')','gt'=>$sqlField.'>:'.$param,'gte'=>$sqlField.'>=:'.$param,'lt'=>$sqlField.'<:'.$param,'lte'=>$sqlField.'<=:'.$param};$args[$param]=$op==='contains'?'%'.(string)($f['value']??'').'%':($f['value']??null);}
        $order=[];foreach($sort as$s){$field=(string)($s['field']??'');$dir=strtolower((string)($s['dir']??'asc'));if(!in_array($field,$allowed,true)||!in_array($dir,['asc','desc'],true))throw new InvalidArgumentException('Unsafe query sort.');$order[]='"'.str_replace('"','',$field).'" '.strtoupper($dir);}
        return['where'=>$where?' WHERE '.implode(' AND ',$where):'','order'=>$order?' ORDER BY '.implode(', ',$order):'','args'=>$args];
    }
    private static function validateDefinition(array$d): void
    {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{2,78}$/',(string)($d['app_key']??''))||mb_strlen((string)($d['label']??''))<2||!is_array($d['entities']??null)||$d['entities']===[])throw new InvalidArgumentException('Invalid app definition.');$entities=[];foreach($d['entities'] as$e){$k=(string)($e['key']??'');if(!preg_match('/^[a-z][a-z0-9_-]{1,78}$/',$k)||isset($entities[$k]))throw new InvalidArgumentException('Invalid entity.');$entities[$k]=true;}
        foreach($d['relations']??[] as$r){if(!isset($entities[$r['source']??''],$entities[$r['target']??''])||!in_array($r['type']??'', ['one_to_one','one_to_many','many_to_many'],true))throw new InvalidArgumentException('Invalid relation.');}
        $views=[];foreach($d['views']??[] as$v){$k=(string)($v['key']??'');if(!preg_match('/^[a-z][a-z0-9_-]{1,78}$/',$k)||!isset($entities[$v['entity']??''])||!in_array($v['type']??'', ['list','detail','form','dashboard'],true))throw new InvalidArgumentException('Invalid view.');$views[$k]=true;}
        foreach($d['routes']??[] as$r){if(!isset($views[$r['view']??''])||!preg_match('#^/[A-Za-z0-9/_-]{1,300}$#',(string)($r['path']??'')))throw new InvalidArgumentException('Invalid route.');}
    }
}
