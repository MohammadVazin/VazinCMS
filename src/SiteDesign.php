<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/** Site-scoped global design settings in the existing cms_settings table.
 * No raw CSS, arbitrary font, URL, PHP template or script is accepted.
 * Preview does not call save. Every save/restore uses a revision guard.
 */
final class SiteDesign
{
    private const KEY='site_design_v1';
    private bool $scoped;
    public function __construct(private PDO $pdo,private int $siteId)
    {
        if($siteId<1)throw new RuntimeException('design_site_unavailable');
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){
            $columns=$pdo->query('PRAGMA table_info(cms_settings)')->fetchAll(PDO::FETCH_ASSOC);
            if(!$columns)throw new RuntimeException('design_schema_unavailable');
            $this->scoped=in_array('site_id',array_column($columns,'name'),true);
        }elseif($driver==='pgsql'){
            $q=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='cms_settings'");
            $columns=$q->fetchAll(PDO::FETCH_COLUMN);
            if(!$columns)throw new RuntimeException('design_schema_unavailable');
            $this->scoped=in_array('site_id',$columns,true);
        }else throw new RuntimeException('design_driver_unsupported');
        $this->assertSite(false);
    }
    public static function defaults():array
    {
        return ['primary'=>'#3156d3','background'=>'#f5f7fb','surface'=>'#ffffff',
            'text'=>'#111827','content_width'=>'1180','text_size'=>'17',
            'radius'=>'16','spacing'=>'24','header'=>'inline'];
    }
    public static function presets():array
    {
        return [
            'corporate'=>['label'=>'شرکتی','values'=>self::defaults()],
            'editorial'=>['label'=>'نشریه','values'=>array_replace(self::defaults(),[
                'primary'=>'#8c3148','background'=>'#fffaf2','text'=>'#30281f',
                'content_width'=>'960','text_size'=>'19','radius'=>'8','spacing'=>'32','header'=>'centered'])],
            'minimal'=>['label'=>'مینیمال','values'=>array_replace(self::defaults(),[
                'primary'=>'#243b53','background'=>'#ffffff','text'=>'#182b3a',
                'content_width'=>'1180','text_size'=>'16','radius'=>'0','spacing'=>'16'])]
        ];
    }
    public static function validate(array $input):array
    {
        $defaults=self::defaults();$keys=array_keys($input);$expected=array_keys($defaults);
        sort($keys);sort($expected);
        if($keys!==$expected)throw new RuntimeException('design_fields_invalid');
        $clean=[];
        foreach($defaults as $key=>$unused){
            if(!is_string($input[$key])&&!is_int($input[$key]))throw new RuntimeException('design_value_invalid');
            $value=trim((string)$input[$key]);
            if(in_array($key,['primary','background','surface','text'],true)){
                if(preg_match('/\A#[a-fA-F0-9]{6}\z/D',$value)!==1)throw new RuntimeException('design_color_invalid');
                $value=strtolower($value);
            }else{
                $allowed=match($key){
                    'content_width'=>['720','960','1180','1360'],
                    'text_size'=>['14','15','16','17','18','19','20','21','22'],
                    'radius'=>['0','8','16','24'],'spacing'=>['16','24','32'],
                    'header'=>['inline','centered'],default=>[]
                };
                if(!in_array($value,$allowed,true))throw new RuntimeException('design_value_invalid');
            }
            $clean[$key]=$value;
        }
        if(self::contrast($clean['text'],$clean['background'])<4.5 ||
           self::contrast($clean['text'],$clean['surface'])<4.5 ||
           self::contrast($clean['primary'],'#ffffff')<4.5){
            throw new RuntimeException('design_contrast_too_low');
        }
        return $clean;
    }
    private static function contrast(string $a,string $b):float
    {
        $luma=static function(string $hex):float{
            $rgb=[];
            foreach([1,3,5] as $offset){
                $v=hexdec(substr($hex,$offset,2))/255;
                $rgb[]=$v<=0.04045?$v/12.92:(($v+0.055)/1.055)**2.4;
            }
            return .2126*$rgb[0]+.7152*$rgb[1]+.0722*$rgb[2];
        };
        $x=$luma($a);$y=$luma($b);return (max($x,$y)+.05)/(min($x,$y)+.05);
    }
    private function assertSite(bool $lock):void
    {
        if(!$this->scoped&&(int)$this->pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn()!==1)
            throw new RuntimeException('design_schema_unavailable');
        $sql="SELECT id FROM cms_sites WHERE id=:id AND status IN('active','staging')";
        if($lock&&(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql')$sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);$q->execute(['id'=>$this->siteId]);
        try{$ok=$q->fetchColumn()!==false;}finally{$q->closeCursor();}
        if(!$ok)throw new RuntimeException('design_site_unavailable');
    }
    private function authorize(int $actorId):void
    {
        if($actorId<1)throw new RuntimeException('design_access_denied');
        $this->assertSite(false);
        if($this->scoped){
            $q=$this->pdo->prepare("SELECT m.site_role FROM cms_site_memberships m JOIN users u ON u.id=m.user_id WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active'");
            $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        }else{
            $q=$this->pdo->prepare("SELECT role FROM users WHERE id=:actor AND status='active'");
            $q->execute(['actor'=>$actorId]);
        }
        try{$role=$q->fetchColumn();}finally{$q->closeCursor();}
        if(!in_array($role,['owner','admin'],true))throw new RuntimeException('design_access_denied');
    }
    private function raw():?string
    {
        $sql='SELECT setting_value FROM cms_settings WHERE setting_key=:key';
        $params=['key'=>self::KEY];
        if($this->scoped){$sql.=' AND site_id=:site';$params['site']=$this->siteId;}
        $q=$this->pdo->prepare($sql);$q->execute($params);
        try{$value=$q->fetchColumn();}finally{$q->closeCursor();}
        return $value===false?null:(string)$value;
    }
    private static function decode(?string $raw):array
    {
        $base=['enabled'=>false,'values'=>self::defaults(),'previous'=>null,
            'version'=>hash('sha256',$raw===null?'not-configured':$raw)];
        if($raw===null)return $base;
        try{$value=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('design_stored_data_invalid');}
        if(!is_array($value)||($value['schema']??null)!==1||!is_bool($value['enabled']??null)||
           !is_string($value['revision']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$value['revision'])!==1||
           !is_array($value['values']??null))throw new RuntimeException('design_stored_data_invalid');
        $base['enabled']=$value['enabled'];$base['values']=self::validate($value['values']);
        if(is_array($value['previous']??null)){
            if(!is_bool($value['previous']['enabled']??null)||!is_array($value['previous']['values']??null))throw new RuntimeException('design_stored_data_invalid');
            $base['previous']=['enabled'=>$value['previous']['enabled'],'values'=>self::validate($value['previous']['values'])];
        }
        return $base;
    }
    public function read(int $actorId):array
    {
        $this->authorize($actorId);return self::decode($this->raw());
    }
    /** Public output returns only validated design data, never actor IDs or private settings. */
    public function publicState():?array
    {
        $value=self::decode($this->raw());
        return $value['enabled']?['values'=>$value['values'],'enabled'=>true]:null;
    }
    public function save(int $actorId,array $input,string $expected,string $action='save'):array
    {
        $this->authorize($actorId);
        if(!in_array($action,['save','reset','restore'],true))throw new RuntimeException('design_action_invalid');
        if(preg_match('/\A[a-f0-9]{64}\z/D',$expected)!==1)throw new RuntimeException('design_changed');
        $values=$action==='save'?self::validate($input):null;
        return DatabaseTransaction::immediate($this->pdo,function()use($actorId,$values,$expected,$action):array{
            $this->assertSite(true);$this->authorize($actorId);
            $before=self::decode($this->raw());
            if(!hash_equals($before['version'],$expected))throw new RuntimeException('design_changed');
            $next=match($action){
                'save'=>['enabled'=>true,'values'=>$values],
                'reset'=>['enabled'=>false,'values'=>self::defaults()],
                'restore'=>$before['previous']??throw new RuntimeException('design_previous_unavailable')
            };
            $record=['schema'=>1,'enabled'=>$next['enabled'],'values'=>$next['values'],
                'revision'=>bin2hex(random_bytes(16)),
                'previous'=>['enabled'=>$before['enabled'],'values'=>$before['values']]];
            $json=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $params=['key'=>self::KEY,'value'=>$json];
            if($this->scoped){
                $params['site']=$this->siteId;
                $sql='INSERT INTO cms_settings(site_id,setting_key,setting_value) VALUES(:site,:key,:value) ON CONFLICT(site_id,setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP';
            }else $sql='INSERT INTO cms_settings(setting_key,setting_value) VALUES(:key,:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP';
            $this->pdo->prepare($sql)->execute($params);
            return self::decode($json);
        });
    }
    public static function variables(array $values):string
    {
        $v=self::validate($values);
        $pairs=['--vz-design-primary'=>$v['primary'],'--vz-design-bg'=>$v['background'],
            '--vz-design-surface'=>$v['surface'],'--vz-design-text'=>$v['text'],
            '--vz-design-width'=>$v['content_width'].'px','--vz-design-font'=>$v['text_size'].'px',
            '--vz-design-radius'=>$v['radius'].'px','--vz-design-spacing'=>$v['spacing'].'px'];
        return implode(';',array_map(static fn($key,$value)=>$key.':'.$value,array_keys($pairs),array_values($pairs)));
    }
}
