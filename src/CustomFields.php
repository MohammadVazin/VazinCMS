<?php
declare(strict_types=1);
namespace VazinCMS;

use PDO;
use InvalidArgumentException;

final class CustomFields
{
    public static function define(PDO $pdo,string$key,string$label,string$entity,string$type,array$validation=[]): int
    {
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,188}$/',$key)||mb_strlen($label)<2)throw new InvalidArgumentException('Invalid custom field.');
        $pdo->prepare('INSERT INTO cms_custom_field_definitions(field_key,label,entity_type,field_type,validation_json) VALUES(:key,:label,:entity,:type,:validation)')->execute(['key'=>$key,'label'=>$label,'entity'=>$entity,'type'=>$type,'validation'=>json_encode($validation)]);return(int)$pdo->lastInsertId();
    }
    public static function set(PDO $pdo,int$fieldId,string$entity,int$entityId,mixed$value): void
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql=$driver==='sqlite'?'INSERT INTO cms_custom_field_values(field_id,entity_type,entity_id,value_json) VALUES(:field,:entity,:id,:value) ON CONFLICT(field_id,entity_type,entity_id) DO UPDATE SET value_json=:value2,updated_at=CURRENT_TIMESTAMP':'INSERT INTO cms_custom_field_values(field_id,entity_type,entity_id,value_json) VALUES(:field,:entity,:id,:value) ON CONFLICT(field_id,entity_type,entity_id) DO UPDATE SET value_json=EXCLUDED.value_json,updated_at=CURRENT_TIMESTAMP';$args=['field'=>$fieldId,'entity'=>$entity,'id'=>$entityId,'value'=>$json];if($driver==='sqlite')$args['value2']=$json;$pdo->prepare($sql)->execute($args);
    }
    public static function get(PDO $pdo,int$fieldId,string$entity,int$entityId):mixed{$q=$pdo->prepare('SELECT value_json FROM cms_custom_field_values WHERE field_id=:field AND entity_type=:entity AND entity_id=:id');$q->execute(['field'=>$fieldId,'entity'=>$entity,'id'=>$entityId]);$v=$q->fetchColumn();return$v===false?null:json_decode((string)$v,true);}
}
