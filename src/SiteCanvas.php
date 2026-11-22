<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * One site-owned design workspace for template body/archive, page overrides,
 * canvas-managed header/footer and the shared component library. Every change
 * is captured in the same bounded history snapshot.
 */
final class SiteCanvas
{
    private const KEY='site_canvas_v1';
    public const HISTORY_LIMIT=20;
    private bool $scoped;
    private SiteTemplateBuilder $templates;

    public function __construct(private readonly PDO $pdo,private readonly int $siteId)
    {
        $this->templates=new SiteTemplateBuilder($pdo,$siteId);
        $this->scoped=in_array('site_id',$this->columns('cms_settings'),true);
        if($this->scoped!==in_array('site_id',$this->columns('cms_pages'),true)){
            throw new RuntimeException('canvas_schema_mismatch');
        }
    }
    private function columns(string $table):array
    {
        if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            return array_column($this->pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
        }
        $s=$this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=:t');
        $s->execute(['t'=>$table]);return $s->fetchAll(PDO::FETCH_COLUMN);
    }
    public static function defaults(string $kind):array
    {
        if(!in_array($kind,['page','archive','header','footer'],true))throw new RuntimeException('canvas_kind_invalid');
        if(in_array($kind,['header','footer'],true))return [];
        return [['id'=>'n-10000001','type'=>'section','layout'=>'stack','tone'=>'surface','gap'=>'normal','children'=>[
            ['id'=>'n-10000002','type'=>$kind.'_title'],
            ['id'=>'n-10000003','type'=>$kind==='page'?'page_content':'archive_list']
        ]]];
    }
    private static function keys(array $x,array $keys):void
    {
        $actual=array_keys($x);sort($actual);sort($keys);
        if($actual!==$keys)throw new RuntimeException('canvas_fields_invalid');
    }
    private static function text(mixed $v,int $max):string
    {
        if(!is_string($v)||mb_strlen($v)>$max)throw new RuntimeException('canvas_text_invalid');
        return $v;
    }
    public static function href(mixed $v):string
    {
        if(!is_string($v)||preg_match('~\A/(?!/)[A-Za-z0-9/_?&=.%#-]{0,239}\z~D',$v)!==1)throw new RuntimeException('canvas_link_invalid');
        $d=$v;
        for($i=0;$i<4;$i++){
            $n=rawurldecode($d);
            if(str_starts_with($n,'//')||str_contains($n,'..')||str_contains($n,chr(92))||preg_match('/[\x00-\x20\x7f]/',$n))throw new RuntimeException('canvas_link_invalid');
            if($n===$d)break;$d=$n;
        }
        if(preg_match('/%[0-9a-f]{2}/i',$d))throw new RuntimeException('canvas_link_invalid');
        return $v;
    }
    /** Bounded trees; shared references are allowed in every rendered region but not inside shared components. */
    public static function tree(mixed $nodes,string $kind,array $shared):array
    {
        if(!in_array($kind,['page','archive','header','footer','component'],true))throw new RuntimeException('canvas_kind_invalid');
        $count=0;$ids=[];$slots=[];
        $walk=function(mixed $nodes,int $depth)use(&$walk,&$count,&$ids,&$slots,$kind,$shared):array{
            if(!is_array($nodes)||!array_is_list($nodes)||$depth>5)throw new RuntimeException('canvas_tree_invalid');
            $clean=[];
            foreach($nodes as $n){
                if(++$count>80||!is_array($n)||!is_string($n['id']??null)||preg_match('/\An-[0-9a-f]{8}\z/D',$n['id'])!==1||isset($ids[$n['id']]))throw new RuntimeException('canvas_node_invalid');
                $ids[$n['id']]=true;$type=$n['type']??'';
                switch($type){
                    case 'section':
                        self::keys($n,['id','type','layout','tone','gap','children']);
                        if(!in_array($n['layout'],['stack','grid2','grid3'],true)||!in_array($n['tone'],['surface','muted','accent'],true)||!in_array($n['gap'],['compact','normal','wide'],true))throw new RuntimeException('canvas_style_invalid');
                        $n['children']=$walk($n['children'],$depth+1);break;
                    case 'heading':
                        self::keys($n,['id','type','text','level']);self::text($n['text'],200);
                        if(!in_array($n['level'],[2,3,4],true))throw new RuntimeException('canvas_heading_invalid');break;
                    case 'text':self::keys($n,['id','type','text']);self::text($n['text'],2000);break;
                    case 'button':self::keys($n,['id','type','text','href']);self::text($n['text'],80);self::href($n['href']);break;
                    case 'shared':
                        self::keys($n,['id','type','ref']);
                        if($kind==='component'||!is_string($n['ref'])||!isset($shared[$n['ref']]))throw new RuntimeException('canvas_reference_invalid');break;
                    case 'page_title':case 'page_content':case 'archive_title':case 'archive_list':
                        self::keys($n,['id','type']);
                        if(($kind==='page'&&!in_array($type,['page_title','page_content'],true))||
                           ($kind==='archive'&&!in_array($type,['archive_title','archive_list'],true))||
                           !in_array($kind,['page','archive'],true))throw new RuntimeException('canvas_slot_invalid');
                        $slots[$type]=($slots[$type]??0)+1;break;
                    default:throw new RuntimeException('canvas_type_invalid');
                }
                $clean[]=$n;
            }
            return $clean;
        };
        $out=$walk($nodes,1);
        foreach($kind==='page'?['page_title','page_content']:($kind==='archive'?['archive_title','archive_list']:[]) as $slot){
            if(($slots[$slot]??0)!==1)throw new RuntimeException('canvas_required_slot');
        }
        return $out;
    }
    public static function validate(array $snapshot):array
    {
        $keys=array_keys($snapshot);sort($keys);
        $old=['layouts','shared'];sort($old);
        $new=['layouts','pageLayouts','shared'];sort($new);
        if($keys!==$old&&$keys!==$new)throw new RuntimeException('canvas_fields_invalid');
        $pageLayouts=$snapshot['pageLayouts']??[];
        if(!is_array($snapshot['layouts'])||count($snapshot['layouts'])>8||
           !is_array($snapshot['shared'])||count($snapshot['shared'])>16||
           !is_array($pageLayouts)||count($pageLayouts)>1000)throw new RuntimeException('canvas_collection_invalid');
        $shared=[];
        foreach($snapshot['shared'] as $id=>$component){
            if(!is_string($id)||preg_match('/\As-[0-9a-f]{8}\z/D',$id)!==1||!is_array($component))throw new RuntimeException('canvas_component_invalid');
            self::keys($component,['label','nodes']);
            $label=self::text($component['label'],80);
            if(trim($label)==='')throw new RuntimeException('canvas_component_invalid');
            $shared[$id]=['label'=>$label,'nodes'=>self::tree($component['nodes'],'component',[])];
        }
        $layouts=[];
        foreach($snapshot['layouts'] as $key=>$pair){
            if(!is_string($key)||preg_match('/\A(corporate|editorial|minimal|custom-[0-9a-f]{8})\z/D',$key)!==1||!is_array($pair))throw new RuntimeException('canvas_template_invalid');
            $layouts[$key]=[];
            foreach($pair as $kind=>$nodes){
                if(!in_array($kind,['page','archive','header','footer'],true))throw new RuntimeException('canvas_kind_invalid');
                $layouts[$key][$kind]=self::tree($nodes,$kind,$shared);
            }
        }
        $cleanPages=[];
        foreach($pageLayouts as $pageId=>$nodes){
            $id=(string)$pageId;
            if(preg_match('/\A[1-9][0-9]{0,9}\z/D',$id)!==1)throw new RuntimeException('canvas_page_layout_invalid');
            $cleanPages[$id]=self::tree($nodes,'page',$shared);
        }
        return ['layouts'=>$layouts,'shared'=>$shared,'pageLayouts'=>$cleanPages];
    }
    private function raw():?string
    {
        $s=$this->pdo->prepare('SELECT setting_value FROM cms_settings WHERE setting_key=:k'.($this->scoped?' AND site_id=:s':''));
        $p=['k'=>self::KEY];if($this->scoped)$p['s']=$this->siteId;
        $s->execute($p);try{$v=$s->fetchColumn();}finally{$s->closeCursor();}
        return $v===false?null:(string)$v;
    }
    private function state():array
    {
        $raw=$this->raw();
        if($raw===null)return ['snapshot'=>['layouts'=>[],'shared'=>[],'pageLayouts'=>[]],'history'=>[],'revision'=>null,'version'=>hash('sha256','canvas-empty-v1')];
        if(strlen($raw)>6000000)throw new RuntimeException('canvas_stored_invalid');
        try{$s=json_decode($raw,true,48,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('canvas_stored_invalid');}
        if(!is_array($s)||($s['schema']??null)!==1||!is_array($s['history']??null)||!array_is_list($s['history'])||count($s['history'])>self::HISTORY_LIMIT||preg_match('/\A[0-9a-f]{32}\z/D',(string)($s['revision']??''))!==1)throw new RuntimeException('canvas_stored_invalid');
        $current=self::validate($s['snapshot']);
        foreach($s['history'] as $h){
            if(!is_array($h)||preg_match('/\A[0-9a-f]{32}\z/D',(string)($h['id']??''))!==1||!is_int($h['actor']??null)||!is_string($h['at']??null))throw new RuntimeException('canvas_history_invalid');
            self::validate($h['snapshot']);
        }
        return ['snapshot'=>$current,'history'=>$s['history'],'revision'=>$s['revision'],'version'=>hash('sha256',$raw)];
    }
    public function read(int $actor):array
    {
        $this->templates->read($actor);return $this->state();
    }
    private function pageRow(int $pageId):array
    {
        if($pageId<1)throw new RuntimeException('canvas_page_unavailable');
        $sql="SELECT id,title,slug,locale,content_type,status FROM cms_pages WHERE id=:id";
        $args=['id'=>$pageId];
        if(in_array('trash_status',$this->columns('cms_pages'),true))$sql.=" AND trash_status='active'";
        if($this->scoped){$sql.=' AND site_id=:site';$args['site']=$this->siteId;}
        elseif((int)$this->pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn()!==1)throw new RuntimeException('canvas_page_unavailable');
        $q=$this->pdo->prepare($sql);$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!is_array($row))throw new RuntimeException('canvas_page_unavailable');
        return $row;
    }
    public function pageInfo(int $pageId):array{return $this->pageRow($pageId);}
    public function pages():array
    {
        if(!$this->scoped&&(int)$this->pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn()!==1){
            throw new RuntimeException('canvas_site_scope_unavailable');
        }
        $sql="SELECT id,title,slug,locale,content_type,status FROM cms_pages";
        if(in_array('trash_status',$this->columns('cms_pages'),true))$sql.=" WHERE trash_status='active'";
        if($this->scoped)$sql.=(str_contains($sql,' WHERE ')?' AND ':' WHERE ').'site_id=:site';
        $sql.=' ORDER BY updated_at DESC,id DESC LIMIT 200';
        $q=$this->pdo->prepare($sql);$q->execute($this->scoped?['site'=>$this->siteId]:[]);
        try{return $q->fetchAll(PDO::FETCH_ASSOC);}finally{$q->closeCursor();}
    }
    public function publicLayout(string $key,string $kind,?int $pageId=null):?array
    {
        $s=$this->state()['snapshot'];
        if($kind==='page'&&$pageId!==null&&$pageId>0&&isset($s['pageLayouts'][(string)$pageId])){
            return ['nodes'=>$s['pageLayouts'][(string)$pageId],'shared'=>$s['shared'],'kind'=>'page','key'=>$key,'source'=>'page','page_id'=>$pageId];
        }
        if(!isset($s['layouts'][$key][$kind]))return null;
        return ['nodes'=>$s['layouts'][$key][$kind],'shared'=>$s['shared'],'kind'=>$kind,'key'=>$key,'source'=>'template'];
    }
    private function change(int $actor,string $base,string $action,callable $mutate):array
    {
        $this->templates->read($actor);
        return DatabaseTransaction::immediate($this->pdo,function()use($actor,$base,$action,$mutate):array{
            $lock=$this->pdo->prepare('SELECT id FROM cms_sites WHERE id=:s'.($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'?' FOR UPDATE':''));
            $lock->execute(['s'=>$this->siteId]);$lock->fetchColumn();$lock->closeCursor();
            $catalog=$this->templates->read($actor);
            $old=$this->state();
            if(!hash_equals($old['version'],$base))throw new RuntimeException('canvas_changed');
            $next=self::validate($mutate($old,$catalog));
            $history=$old['history'];
            array_unshift($history,['id'=>bin2hex(random_bytes(16)),'at'=>gmdate('c'),'actor'=>$actor,'action'=>$action,'snapshot'=>$old['snapshot']]);
            $history=array_slice($history,0,self::HISTORY_LIMIT);
            $raw=json_encode(['schema'=>1,'revision'=>bin2hex(random_bytes(16)),'snapshot'=>$next,'history'=>$history],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if(strlen($raw)>6000000)throw new RuntimeException('canvas_storage_limit');
            $params=['k'=>self::KEY,'v'=>$raw];
            $columns='setting_key,setting_value';$values=':k,:v';$conflict='setting_key';
            if($this->scoped){$columns='site_id,'.$columns;$values=':s,'.$values;$conflict='site_id,'.$conflict;$params['s']=$this->siteId;}
            $this->pdo->prepare('INSERT INTO cms_settings('.$columns.') VALUES('.$values.') ON CONFLICT('.$conflict.') DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP')->execute($params);
            return $this->state();
        });
    }
    public function save(int $actor,string $base,string $key,string $kind,array $nodes):array
    {
        return $this->change($actor,$base,'save:'.$key.':'.$kind,function($old,$catalog)use($key,$kind,$nodes){
            if(!isset($catalog['templates'][$key])||!in_array($kind,['page','archive','header','footer'],true))throw new RuntimeException('canvas_template_invalid');
            $s=$old['snapshot'];$s['layouts'][$key][$kind]=$nodes;return $s;
        });
    }
    public function savePage(int $actor,string $base,int $pageId,array $nodes):array
    {
        $this->pageRow($pageId);
        return $this->change($actor,$base,'save:page:'.$pageId,function($old)use($pageId,$nodes){
            $this->pageRow($pageId);$s=$old['snapshot'];$s['pageLayouts'][(string)$pageId]=$nodes;return $s;
        });
    }
    public function saveShared(int $actor,string $base,string $id,string $label,array $nodes):array
    {
        return $this->change($actor,$base,'shared:'.$id,function($old)use($id,$label,$nodes){
            $s=$old['snapshot'];$s['shared'][$id]=['label'=>$label,'nodes'=>$nodes];return $s;
        });
    }
    public function restore(int $actor,string $base,string $historyId):array
    {
        return $this->change($actor,$base,'restore',function($old)use($historyId){
            foreach($old['history'] as $h)if(hash_equals($h['id'],$historyId))return $h['snapshot'];
            throw new RuntimeException('canvas_history_unavailable');
        });
    }
    public function reset(int $actor,string $base,string $key,string $kind):array
    {
        if(!in_array($kind,['page','archive','header','footer'],true))throw new RuntimeException('canvas_kind_invalid');
        return $this->change($actor,$base,'reset:'.$key.':'.$kind,function($old,$catalog)use($key,$kind){
            if(!isset($catalog['templates'][$key]))throw new RuntimeException('canvas_template_invalid');
            $s=$old['snapshot'];unset($s['layouts'][$key][$kind]);return $s;
        });
    }
    public function resetPage(int $actor,string $base,int $pageId):array
    {
        $this->pageRow($pageId);
        return $this->change($actor,$base,'reset:page:'.$pageId,function($old)use($pageId){
            $this->pageRow($pageId);$s=$old['snapshot'];unset($s['pageLayouts'][(string)$pageId]);return $s;
        });
    }
    /** Archive only reads published, active CMS posts for this site and locale. */
    public function archive(string $locale,int $page=1):array
    {
        if(!in_array($locale,['fa','ar','en','ru','tr','hy','kk','tg','zh'],true)||$page<1||$page>10000)throw new RuntimeException('canvas_archive_invalid');
        $where="status='published' AND content_type='post' AND locale=:l";
        $p=['l'=>$locale];
        if($this->scoped){$where.=' AND site_id=:s';$p['s']=$this->siteId;}
        if(in_array('trash_status',$this->columns('cms_pages'),true))$where.=" AND trash_status='active'";
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM cms_pages WHERE '.$where);$q->execute($p);$count=(int)$q->fetchColumn();$q->closeCursor();
        $q=$this->pdo->prepare('SELECT id,slug,locale,title,body FROM cms_pages WHERE '.$where.' ORDER BY id DESC LIMIT 12 OFFSET '.(($page-1)*12));
        $q->execute($p);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        return ['items'=>$rows,'total'=>$count,'page'=>$page,'pages'=>max(1,(int)ceil($count/12)),'locale'=>$locale];
    }
}
