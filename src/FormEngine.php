<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class FormEngine
{
    private const TYPES=['text','textarea','email','number','date','select','checkbox','url','phone'];
    public static function create(PDO $pdo,string $key,string $label,array $fields,array $settings=[]): int
    {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{2,188}$/',$key)||mb_strlen(trim($label))<2)throw new InvalidArgumentException('Invalid form metadata.');
        $pdo->prepare("INSERT INTO cms_forms(form_key,label,status,settings_json) VALUES(:key,:label,'active',:settings)")->execute(['key'=>$key,'label'=>$label,'settings'=>json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$id=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT INTO cms_form_fields(form_id,field_key,label,field_type,required,position,validation_json,settings_json) VALUES(:form,:key,:label,:type,:required,:position,:validation,:settings)');
        foreach(array_values($fields) as $i=>$f){$type=(string)($f['type']??'text');$fk=(string)($f['key']??'');if(!in_array($type,self::TYPES,true)||!preg_match('/^[a-z][a-z0-9_]{1,79}$/',$fk))throw new InvalidArgumentException('Invalid field.');$ins->execute(['form'=>$id,'key'=>$fk,'label'=>(string)($f['label']??$fk),'type'=>$type,'required'=>!empty($f['required'])?1:0,'position'=>$i,'validation'=>json_encode($f['validation']??[]),'settings'=>json_encode($f['settings']??[])]);}return$id;
    }
    public static function submit(PDO $pdo,string $key,array $input,?int $userId=null,string $ip='',string $ua=''): int
    {
        $q=$pdo->prepare("SELECT * FROM cms_forms WHERE form_key=:key AND status='active'");$q->execute(['key'=>$key]);$form=$q->fetch();if(!$form)throw new InvalidArgumentException('Form unavailable.');
        $f=$pdo->prepare('SELECT * FROM cms_form_fields WHERE form_id=:id ORDER BY position,id');$f->execute(['id'=>$form['id']]);$clean=[];
        foreach($f->fetchAll() as$row){$name=(string)$row['field_key'];$value=$input[$name]??null;if(!empty($row['required'])&&($value===null||$value===''))throw new InvalidArgumentException('Required field: '.$name);$clean[$name]=self::validate((string)$row['field_type'],$value,json_decode((string)$row['validation_json'],true)?:[]);}
        $pdo->prepare("INSERT INTO cms_form_submissions(form_id,payload_json,source_ip_hash,user_agent_hash,submitted_by) VALUES(:form,:payload,:ip,:ua,:uid)")->execute(['form'=>$form['id'],'payload'=>json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'ip'=>$ip===''?'':hash('sha256',$ip),'ua'=>$ua===''?'':hash('sha256',$ua),'uid'=>$userId]);return(int)$pdo->lastInsertId();
    }
    private static function validate(string$type,mixed$value,array$rules):mixed
    {
        if($value===null)return null;$s=is_scalar($value)?trim((string)$value):'';$max=max(1,min(10000,(int)($rules['max']??2000)));if(mb_strlen($s)>$max)throw new InvalidArgumentException('Field too long.');
        return match($type){'email'=>filter_var($s,FILTER_VALIDATE_EMAIL)?$s:throw new InvalidArgumentException('Invalid email.'),'url'=>filter_var($s,FILTER_VALIDATE_URL)?$s:throw new InvalidArgumentException('Invalid URL.'),'number'=>is_numeric($s)?(float)$s:throw new InvalidArgumentException('Invalid number.'),'checkbox'=>(bool)$value,default=>$s};
    }
}
